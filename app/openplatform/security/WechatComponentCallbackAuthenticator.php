<?php

declare(strict_types=1);

namespace app\openplatform\security;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\openplatform\contract\ComponentCredentialProvider;
use app\openplatform\contract\ComponentPlatformRepository;
use app\openplatform\domain\AuthenticatedComponentEvent;
use DateTimeImmutable;
use DateTimeZone;

final readonly class WechatComponentCallbackAuthenticator
{
    public function __construct(
        private ComponentPlatformRepository $platforms,
        private ComponentCredentialProvider $credentials,
        private WechatComponentSignatureVerifier $signatureVerifier,
        private WechatComponentEnvelopeParser $parser,
        private WechatComponentMessageDecryptor $decryptor,
        private int $freshnessSeconds = 300,
    ) {
        if ($freshnessSeconds < 0) {
            throw new \InvalidArgumentException('Callback freshness window must not be negative.');
        }
    }

    public function authenticate(
        string $componentPlatformId,
        string $rawBody,
        string $timestamp,
        string $nonce,
        string $msgSignature,
        DateTimeImmutable $now,
    ): AuthenticatedComponentEvent {
        $platform = $this->platforms->findById($componentPlatformId);
        if ($platform === null || !$platform->enabled()) {
            throw new AppException(ErrorCode::NOT_FOUND, 'OpenPlatform component platform not found.', 404);
        }

        $envelope = $this->parser->parseOuter($rawBody);
        $this->assertFresh($timestamp, $nonce, $now);

        $verifyToken = $this->credentials->secretFor($platform->verifyTokenRef());
        $this->signatureVerifier->verify(
            $verifyToken,
            $timestamp,
            $nonce,
            $envelope->encryptedPayload(),
            $msgSignature,
            $now,
        );

        $replayKey = hash('sha256', $componentPlatformId . "\n" . $timestamp . "\n" . $nonce);
        $payloadHash = hash('sha256', $envelope->encryptedPayload());

        $encodingAesKey = $this->credentials->secretFor($platform->encodingAesKeyRef());
        $innerXml = $this->decryptor->decrypt(
            $envelope->encryptedPayload(),
            $encodingAesKey,
            $platform->componentAppId(),
        );
        $inner = $this->parser->parseInnerEvent($innerXml);
        if (!hash_equals($platform->componentAppId(), $inner['appId'])) {
            throw new AppException(ErrorCode::FORBIDDEN, 'OpenPlatform inner AppId mismatch.', 403);
        }

        $sourceTimestamp = (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'));

        return new AuthenticatedComponentEvent(
            $componentPlatformId,
            $platform->componentAppId(),
            $inner['infoType'],
            $sourceTimestamp,
            $replayKey,
            $payloadHash,
            $inner['componentVerifyTicket'],
            $inner['authorizerAppId'],
            $inner['authorizationCode'],
            $inner['authorizationCodeExpiredTime'],
            $inner['preAuthCode'],
        );
    }

    private function assertFresh(string $timestamp, string $nonce, DateTimeImmutable $now): void
    {
        if (
            trim($nonce) === ''
            || !preg_match('/^\d+$/', $timestamp)
            || abs($now->getTimestamp() - (int) $timestamp) > $this->freshnessSeconds
        ) {
            throw new AppException(ErrorCode::UNAUTHORIZED, 'Invalid OpenPlatform callback authentication.', 401);
        }
    }
}
