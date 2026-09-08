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
use app\openplatform\domain\AuthenticatedComponentEvent;
use app\openplatform\domain\ComponentVerifyTicket;
use app\openplatform\security\WechatComponentCallbackAuthenticator;
use app\openplatform\security\WechatComponentEnvelopeParser;
use app\openplatform\security\WechatComponentMessageDecryptor;
use app\openplatform\security\WechatComponentSignatureVerifier;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

final readonly class ComponentTicketService
{
    private WechatComponentCallbackAuthenticator $authenticator;
    private ComponentTicketRepository $tickets;
    private AuditLogger $audit;

    public function __construct(
        WechatComponentCallbackAuthenticator|ComponentPlatformRepository $authenticatorOrPlatforms,
        ComponentTicketRepository|ComponentCredentialProvider $ticketsOrCredentials,
        AuditLogger|WechatComponentSignatureVerifier $auditOrVerifier,
        ?WechatComponentEnvelopeParser $legacyParser = null,
        ?WechatComponentMessageDecryptor $legacyDecryptor = null,
        ?ComponentTicketRepository $legacyTickets = null,
        ?AuditLogger $legacyAudit = null,
        int $freshnessSeconds = 300,
    ) {
        if ($authenticatorOrPlatforms instanceof WechatComponentCallbackAuthenticator) {
            if (!$ticketsOrCredentials instanceof ComponentTicketRepository || !$auditOrVerifier instanceof AuditLogger) {
                throw new InvalidArgumentException('Invalid unified ComponentTicketService dependencies.');
            }
            $this->authenticator = $authenticatorOrPlatforms;
            $this->tickets = $ticketsOrCredentials;
            $this->audit = $auditOrVerifier;
            return;
        }

        if (
            !$ticketsOrCredentials instanceof ComponentCredentialProvider
            || !$auditOrVerifier instanceof WechatComponentSignatureVerifier
            || $legacyParser === null
            || $legacyDecryptor === null
            || $legacyTickets === null
            || $legacyAudit === null
        ) {
            throw new InvalidArgumentException('Invalid legacy ComponentTicketService dependencies.');
        }

        $this->authenticator = new WechatComponentCallbackAuthenticator(
            $authenticatorOrPlatforms,
            $ticketsOrCredentials,
            $auditOrVerifier,
            $legacyParser,
            $legacyDecryptor,
            $freshnessSeconds,
        );
        $this->tickets = $legacyTickets;
        $this->audit = $legacyAudit;
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
        $event = $this->authenticator->authenticate(
            $componentPlatformId,
            $rawBody,
            $timestamp,
            $nonce,
            $msgSignature,
            $now,
        );
        $this->acceptAuthenticatedEvent($event, $now, $requestId, $traceId);
    }

    public function acceptAuthenticatedEvent(
        AuthenticatedComponentEvent $event,
        DateTimeImmutable $receivedAt,
        string $requestId,
        string $traceId,
    ): void {
        $ticketValue = $event->componentVerifyTicket();
        if ($event->infoType() !== 'component_verify_ticket' || $ticketValue === null || trim($ticketValue) === '') {
            throw new AppException(ErrorCode::INVALID_ARGUMENT, 'Unsupported OpenPlatform callback InfoType for ticket route.', 400);
        }

        $ticket = new ComponentVerifyTicket(
            $event->componentPlatformId(),
            $ticketValue,
            hash('sha256', $ticketValue),
            $event->sourceTimestamp(),
            $receivedAt,
            0,
        );
        $result = $this->tickets->accept($ticket, $event->replayKey(), $event->payloadHash());

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
                    'component_platform_id' => $event->componentPlatformId(),
                    'component_app_id' => $event->componentAppId(),
                    'duplicate' => $result->duplicate(),
                    'ticket_version' => $result->ticketVersion(),
                    'outcome' => $result->stale() ? 'stale' : ($result->duplicate() ? 'duplicate' : 'accepted'),
                ],
                $receivedAt,
            ));
        } catch (Throwable) {
            // Audit transport failure must not roll back an already accepted provider callback.
        }
    }
}
