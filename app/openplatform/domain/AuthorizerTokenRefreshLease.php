<?php

declare(strict_types=1);

namespace app\openplatform\domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AuthorizerTokenRefreshLease
{
    public function __construct(
        private string $componentPlatformId,
        private string $authorizerAppId,
        private string $holderId,
        private DateTimeImmutable $leaseExpiresAt,
        private int $version,
    ) {
        if (trim($componentPlatformId) === '' || trim($authorizerAppId) === '' || trim($holderId) === '' || $version < 0) {
            throw new InvalidArgumentException('Invalid authorizer token refresh lease.');
        }
    }

    public function componentPlatformId(): string { return $this->componentPlatformId; }
    public function authorizerAppId(): string { return $this->authorizerAppId; }
    public function holderId(): string { return $this->holderId; }
    public function leaseExpiresAt(): DateTimeImmutable { return $this->leaseExpiresAt; }
    public function version(): int { return $this->version; }

    public function heldBy(string $holderId, DateTimeImmutable $now): bool
    {
        return hash_equals($this->holderId, $holderId) && $this->leaseExpiresAt > $now;
    }
}
