<?php

declare(strict_types=1);

namespace modules\webhook\contract;

use modules\webhook\domain\WechatWebhookEvent;
use modules\webhook\domain\WebhookInboxResult;
use DateTimeImmutable;

interface WebhookInboxRepository
{
    public function receive(WechatWebhookEvent $event, DateTimeImmutable $receivedAt): WebhookInboxResult;
    public function markDispatched(string $inboxId, DateTimeImmutable $dispatchedAt): void;
}
