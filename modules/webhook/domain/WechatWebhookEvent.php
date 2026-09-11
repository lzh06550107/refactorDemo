<?php

declare(strict_types=1);

namespace modules\webhook\domain;

use InvalidArgumentException;

final readonly class WechatWebhookEvent
{
    public function __construct(
        private string $tenantId,
        private string $providerType,
        private string $providerAccountId,
        private string $providerEventKey,
        private string $rawBodyHash,
    ) {
        foreach ([$tenantId, $providerType, $providerAccountId, $providerEventKey] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Webhook event identifiers must not be empty.');
            }
        }
        if (!preg_match('/^[a-f0-9]{64}$/i', $rawBodyHash)) {
            throw new InvalidArgumentException('Webhook raw body hash must be a SHA-256 hex digest.');
        }
    }

    public function tenantId(): string { return $this->tenantId; }
    public function providerType(): string { return $this->providerType; }
    public function providerAccountId(): string { return $this->providerAccountId; }
    public function providerEventKey(): string { return $this->providerEventKey; }
    public function rawBodyHash(): string { return $this->rawBodyHash; }
}
