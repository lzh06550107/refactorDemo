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
            // R8B ticket repository remains the authoritative ticket replay/write gate.
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
}
