<?php

declare(strict_types=1);

namespace app\openplatform\contract;

use app\openplatform\domain\ComponentTokenRefreshLease;
use DateTimeImmutable;

interface ComponentRefreshLeaseRepository
{
    public function tryAcquire(string $componentPlatformId, string $holderId, DateTimeImmutable $now, int $leaseSeconds): ?ComponentTokenRefreshLease;
    public function release(string $componentPlatformId, string $holderId): void;
}
