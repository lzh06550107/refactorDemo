<?php

declare(strict_types=1);

namespace app\openplatform\contract;

use DateTimeImmutable;

interface ProvisioningJobScheduler
{
    public function requeue(string $provisioningId, DateTimeImmutable $now): bool;
}
