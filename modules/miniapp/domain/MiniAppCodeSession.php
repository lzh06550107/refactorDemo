<?php

declare(strict_types=1);

namespace modules\miniapp\domain;

use InvalidArgumentException;

final readonly class MiniAppCodeSession
{
    public function __construct(
        private string $providerAppId,
        private string $openId,
        private ?string $unionId,
        private string $sessionKey,
    ) {
        if (trim($providerAppId) === '' || trim($openId) === '' || trim($sessionKey) === '') {
            throw new InvalidArgumentException('MiniApp code session requires providerAppId, openId and sessionKey.');
        }
    }

    public function providerAppId(): string { return $this->providerAppId; }
    public function openId(): string { return $this->openId; }
    public function unionId(): ?string { return $this->unionId; }
    public function sessionKey(): string { return $this->sessionKey; }
}
