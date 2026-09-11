<?php

declare(strict_types=1);

namespace modules\openplatform\contract;

use DateTimeImmutable;

interface ProvisioningJobSource
{
    /** @return list<string> */
    public function dueProvisioningIds(DateTimeImmutable $now, int $limit): array;
}
