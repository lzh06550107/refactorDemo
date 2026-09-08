<?php

declare(strict_types=1);

namespace app\openplatform\domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ComponentTokenRefreshLease
{
    public function __construct(
        private string $componentPlatformId,
        private string $holderId,
        private DateTimeImmutable $leaseExpiresAt,
        private int $version,
    ) {
        if (trim($componentPlatformId) === '' || trim($holderId) === '' || $version < 0) {
            throw new InvalidArgumentException('Invalid component token refresh lease.');
        }
    }

    public function componentPlatformId(): string { return $this->componentPlatformId; }
    public function holderId(): string { return $this->holderId; }
    public function leaseExpiresAt(): DateTimeImmutable { return $this->leaseExpiresAt; }
    public function version(): int { return $this->version; }
}
