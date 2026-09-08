<?php

declare(strict_types=1);

namespace app\webhook\domain;

use InvalidArgumentException;

final readonly class WechatWebhookRequest
{
    public function __construct(
        private string $tenantId,
        private string $providerAccountId,
        private string $token,
        private string $signature,
        private string $timestamp,
        private string $nonce,
        private string $rawBody,
    ) {
        foreach ([$tenantId, $providerAccountId, $token, $signature, $timestamp, $nonce] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('WeChat webhook request metadata must not be empty.');
            }
        }
        if ($rawBody === '') {
            throw new InvalidArgumentException('WeChat webhook raw body must not be empty.');
        }
    }

    public function tenantId(): string { return $this->tenantId; }
    public function providerAccountId(): string { return $this->providerAccountId; }
    public function token(): string { return $this->token; }
    public function signature(): string { return $this->signature; }
    public function timestamp(): string { return $this->timestamp; }
    public function nonce(): string { return $this->nonce; }
    public function rawBody(): string { return $this->rawBody; }
}
