<?php

declare(strict_types=1);

namespace app\webhook\domain;

use InvalidArgumentException;

final readonly class WebhookInboxResult
{
    private function __construct(
        private string $inboxId,
        private bool $duplicate,
    ) {
        if (trim($inboxId) === '') {
            throw new InvalidArgumentException('Webhook inbox id must not be empty.');
        }
    }

    public static function accepted(string $inboxId): self
    {
        return new self($inboxId, false);
    }

    public static function replayed(string $inboxId): self
    {
        return new self($inboxId, true);
    }

    public function inboxId(): string { return $this->inboxId; }
    public function duplicate(): bool { return $this->duplicate; }
}
