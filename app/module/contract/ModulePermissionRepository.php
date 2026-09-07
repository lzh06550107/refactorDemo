<?php

declare(strict_types=1);

namespace app\module\contract;

use app\account\domain\LegacyAccountMapping;
use app\iam\domain\LegacyPermissionAssignment;

interface ModulePermissionRepository
{
    public function assignment(int $legacyUid, LegacyAccountMapping $account, string $moduleName): LegacyPermissionAssignment;
}
