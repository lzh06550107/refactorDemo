<?php

declare(strict_types=1);

namespace app\openplatform\application;

use app\openplatform\contract\ComponentEventInboxRepository;
use app\openplatform\security\WechatComponentCallbackAuthenticator;
use DateTimeImmutable;

final readonly class OpenPlatformEventService
{
    public function __construct(
        private WechatComponentCallbackAuthenticator $authenticator,
        private ComponentEventInboxRepository $eventInbox,
        private ComponentTicketService $ticketService,
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
            // R8B ComponentTicketRepository remains the authoritative replay/write gate
            // for ticket events. This permits the generic inbox to reuse the same backing
            // inbox table for lifecycle events without double-inserting the first ticket.
            $this->ticketService->acceptAuthenticatedEvent($event, $now, $requestId, $traceId);
            return;
        }

        $this->eventInbox->accept(
            $event->componentPlatformId(),
            $event->replayKey(),
            $event->payloadHash(),
            $event->infoType(),
            $event->sourceTimestamp(),
            $now,
        );

        // R8C Task 5 will attach authorizer lifecycle dispatch here. At this slice the
        // authenticated, replay-gated event is deliberately accepted without mutation.
    }
}
