<?php

declare(strict_types=1);

namespace modules\account\contract;

use modules\account\domain\LegacyAccountMapping;

interface LegacyAccountMappingRepository
{
    public function findByUniacid(int $uniacid): ?LegacyAccountMapping;

    public function findByAcid(int $acid): ?LegacyAccountMapping;
}
