<?php

declare(strict_types=1);

namespace app\openplatform\contract;

use app\openplatform\domain\AuthorizerTokenRefreshLease;
use DateTimeImmutable;

interface AuthorizerRefreshLeaseRepository
{
    public function tryAcquire(
        string $componentPlatformId,
        string $authorizerAppId,
        string $holderId,
        DateTimeImmutable $now,
        int $leaseSeconds,
    ): ?AuthorizerTokenRefreshLease;

    public function release(string $componentPlatformId, string $authorizerAppId, string $holderId): void;
}
