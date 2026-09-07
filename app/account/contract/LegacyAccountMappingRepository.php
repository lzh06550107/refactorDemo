<?php

declare(strict_types=1);

namespace app\account\contract;

use app\account\domain\LegacyAccountMapping;

interface LegacyAccountMappingRepository
{
    public function findByUniacid(int $uniacid): ?LegacyAccountMapping;

    public function findByAcid(int $acid): ?LegacyAccountMapping;
}
