<?php

declare(strict_types=1);

namespace modules\module\contract;

use modules\account\domain\LegacyAccountMapping;
use modules\iam\domain\LegacyPermissionAssignment;

interface ModulePermissionRepository
{
    public function assignment(int $legacyUid, LegacyAccountMapping $account, string $moduleName): LegacyPermissionAssignment;
}
