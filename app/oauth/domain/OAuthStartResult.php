<?php

declare(strict_types=1);

namespace app\oauth\domain;

use DateTimeImmutable;

final readonly class OAuthStartResult
{
    public function __construct(
        private string $stateToken,
        private string $authorizationUrl,
        private DateTimeImmutable $expiresAt,
    ) {
    }

    public function stateToken(): string { return $this->stateToken; }
    public function authorizationUrl(): string { return $this->authorizationUrl; }
    public function expiresAt(): DateTimeImmutable { return $this->expiresAt; }
}
