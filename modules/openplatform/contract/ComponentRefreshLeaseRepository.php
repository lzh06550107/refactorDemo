<?php

declare(strict_types=1);

namespace modules\openplatform\contract;

use modules\openplatform\domain\ComponentTokenRefreshLease;
use DateTimeImmutable;

interface ComponentRefreshLeaseRepository
{
    public function tryAcquire(string $componentPlatformId, string $holderId, DateTimeImmutable $now, int $leaseSeconds): ?ComponentTokenRefreshLease;
    public function release(string $componentPlatformId, string $holderId): void;
}
