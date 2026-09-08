<?php

declare(strict_types=1);

namespace app\webhook\contract;

use app\webhook\domain\WechatWebhookEvent;
use app\webhook\domain\WebhookInboxResult;
use DateTimeImmutable;

interface WebhookInboxRepository
{
    public function receive(WechatWebhookEvent $event, DateTimeImmutable $receivedAt): WebhookInboxResult;
    public function markDispatched(string $inboxId, DateTimeImmutable $dispatchedAt): void;
}
