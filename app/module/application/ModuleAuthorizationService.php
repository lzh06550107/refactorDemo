<?php

declare(strict_types=1);

namespace app\module\application;

use modules\account\domain\LegacyAccountMapping;
use modules\iam\domain\LegacyPermissionPolicy;
use modules\iam\domain\Permission;
use app\module\contract\ModulePermissionRepository;

final class ModuleAuthorizationService
{
    public function __construct(
        private readonly RuntimeModuleService $runtimeModules,
        private readonly ModulePermissionRepository $permissions,
        private readonly LegacyPermissionPolicy $policy,
    ) {
    }

    public function allows(
        int $legacyUid,
        LegacyAccountMapping $account,
        string $moduleName,
        Permission $permission,
        bool $roleDefaultAllows,
        ?string $frame = null,
    ): bool {
        if ($this->runtimeModules->resolve($account, $moduleName, true) === null) {
            return false;
        }

        return $this->policy->allows(
            $permission,
            $this->permissions->assignment($legacyUid, $account, $moduleName),
            $roleDefaultAllows,
            $frame,
        );
    }
}
