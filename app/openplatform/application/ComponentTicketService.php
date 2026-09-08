<?php

declare(strict_types=1);

namespace app\openplatform\application;

use app\common\audit\AuditEvent;
use app\common\contract\AuditLogger;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\openplatform\contract\ComponentCredentialProvider;
use app\openplatform\contract\ComponentPlatformRepository;
use app\openplatform\contract\ComponentTicketRepository;
use app\openplatform\domain\ComponentVerifyTicket;
use app\openplatform\security\WechatComponentEnvelopeParser;
use app\openplatform\security\WechatComponentMessageDecryptor;
use app\openplatform\security\WechatComponentSignatureVerifier;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final readonly class ComponentTicketService
{
    public function __construct(
        private ComponentPlatformRepository $platforms,
        private ComponentCredentialProvider $credentials,
        private WechatComponentSignatureVerifier $signatureVerifier,
        private WechatComponentEnvelopeParser $parser,
        private WechatComponentMessageDecryptor $decryptor,
        private ComponentTicketRepository $tickets,
        private AuditLogger $audit,
        private int $freshnessSeconds = 300,
    ) {
    }

    public function ingest(
        string $componentPlatformId,
        string $rawBody,
        string $timestamp,
        string $nonce,
        string $msgSignature,
        DateTimeImmutable $now,
        string $requestId,
        string $traceId,
    ): void {
        $platform = $this->platforms->findById($componentPlatformId);
        if ($platform === null || !$platform->enabled()) {
            throw new AppException(ErrorCode::NOT_FOUND, 'OpenPlatform component platform not found.', 404);
        }

        $envelope = $this->parser->parseOuter($rawBody);
        $this->assertFresh($timestamp, $nonce, $now);
        $verifyToken = $this->credentials->secretFor($platform->verifyTokenRef());
        $this->signatureVerifier->verify($verifyToken, $timestamp, $nonce, $envelope->encryptedPayload(), $msgSignature, $now);

        $replayKey = hash('sha256', $componentPlatformId . "\n" . $timestamp . "\n" . $nonce);
        $payloadHash = hash('sha256', $envelope->encryptedPayload());

        $encodingAesKey = $this->credentials->secretFor($platform->encodingAesKeyRef());
        $innerXml = $this->decryptor->decrypt($envelope->encryptedPayload(), $encodingAesKey, $platform->componentAppId());
        $inner = $this->parser->parseInnerTicket($innerXml);
        if (!hash_equals($platform->componentAppId(), $inner['appId'])) {
            throw new AppException(ErrorCode::FORBIDDEN, 'OpenPlatform inner AppId mismatch.', 403);
        }
        if ($inner['infoType'] !== 'component_verify_ticket') {
            throw new AppException(ErrorCode::INVALID_ARGUMENT, 'Unsupported OpenPlatform callback InfoType.', 400);
        }

        $sourceTimestamp = (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'));
        $ticket = new ComponentVerifyTicket(
            $componentPlatformId,
            $inner['ticket'],
            hash('sha256', $inner['ticket']),
            $sourceTimestamp,
            $now,
            0,
        );
        $result = $this->tickets->accept($ticket, $replayKey, $payloadHash);

        try {
            $this->audit->record(new AuditEvent(
                'external:wechat-openplatform',
                null,
                null,
                'openplatform.component_verify_ticket.receive',
                'success',
                $requestId,
                $traceId,
                [
                    'component_platform_id' => $componentPlatformId,
                    'component_app_id' => $platform->componentAppId(),
                    'duplicate' => $result->duplicate(),
                    'ticket_version' => $result->ticketVersion(),
                    'outcome' => $result->stale() ? 'stale' : ($result->duplicate() ? 'duplicate' : 'accepted'),
                ],
                $now,
            ));
        } catch (Throwable) {
            // Audit transport failure must not roll back an already accepted provider callback.
        }
    }

    private function assertFresh(string $timestamp, string $nonce, DateTimeImmutable $now): void
    {
        if (trim($nonce) === '' || !preg_match('/^\d+$/', $timestamp) || abs($now->getTimestamp() - (int) $timestamp) > $this->freshnessSeconds) {
            throw new AppException(ErrorCode::UNAUTHORIZED, 'Invalid OpenPlatform callback authentication.', 401);
        }
    }
}
