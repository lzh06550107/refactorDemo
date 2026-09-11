<?php

declare(strict_types=1);

namespace modules\openplatform\application;

use modules\openplatform\contract\ComponentEventInboxRepository;
use modules\openplatform\security\WechatComponentCallbackAuthenticator;
use DateTimeImmutable;

final readonly class OpenPlatformEventService
{
    public function __construct(
        private WechatComponentCallbackAuthenticator $authenticator,
        private ComponentEventInboxRepository $eventInbox,
        private ComponentTicketService $ticketService,
        private ?AuthorizationEventService $authorizationEvents = null,
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
        $event = $this->authenticator->authenticate(
            $componentPlatformId,
            $rawBody,
            $timestamp,
            $nonce,
            $msgSignature,
            $now,
        );

        if ($event->infoType() === 'component_verify_ticket') {
            $this->ticketService->acceptAuthenticatedEvent($event, $now, $requestId, $traceId);
            return;
        }

        $accepted = $this->eventInbox->accept(
            $event->componentPlatformId(),
            $event->replayKey(),
            $event->payloadHash(),
            $event->infoType(),
            $event->sourceTimestamp(),
            $now,
        );
        if (!$accepted) {
            return;
        }

        if ($this->authorizationEvents !== null) {
            $this->authorizationEvents->handle($event, $now, $requestId, $traceId);
        }
    }

    public function ingestTicket(
        string $componentPlatformId,
        string $rawBody,
        string $timestamp,
        string $nonce,
        string $msgSignature,
        DateTimeImmutable $now,
        string $requestId,
        string $traceId,
    ): void {
        // Compatibility endpoint remains ticket-only while its controller depends on the
        // unified R8C ingress boundary instead of reaching ComponentTicketService directly.
        $this->ticketService->ingest(
            $componentPlatformId,
            $rawBody,
            $timestamp,
            $nonce,
            $msgSignature,
            $now,
            $requestId,
            $traceId,
        );
    }
}
