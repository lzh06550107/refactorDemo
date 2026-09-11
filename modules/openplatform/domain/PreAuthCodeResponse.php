<?php

declare(strict_types=1);

namespace modules\openplatform\domain;

use InvalidArgumentException;

final readonly class PreAuthCodeResponse
{
    public function __construct(
        private string $preAuthCode,
        private int $expiresIn,
    ) {
        if (trim($preAuthCode) === '' || $expiresIn <= 0) {
            throw new InvalidArgumentException('Invalid OpenPlatform pre-auth-code response.');
        }
    }

    public function preAuthCode(): string { return $this->preAuthCode; }
    public function expiresIn(): int { return $this->expiresIn; }
}
