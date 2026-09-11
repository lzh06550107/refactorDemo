<?php

declare(strict_types=1);

namespace modules\openplatform\contract;

use modules\openplatform\domain\ProvisioningJob;
use DateTimeImmutable;

interface ProvisioningJobRepository
{
    public function insert(ProvisioningJob $job): void;

    public function tryClaim(
        string $provisioningId,
        string $holderId,
        DateTimeImmutable $now,
        int $ttlSeconds,
    ): ?ProvisioningJob;

    public function release(
        string $provisioningId,
        string $holderId,
        DateTimeImmutable $nextAttemptAt,
        ?string $errorCode,
    ): bool;

    public function complete(string $provisioningId, string $holderId): bool;

    public function dead(string $provisioningId, string $holderId, string $errorCode): bool;
}
