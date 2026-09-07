<?php

declare(strict_types=1);

namespace app\module\domain;

use app\iam\domain\LegacyPermissionAssignment;
use app\iam\domain\LegacyPermissionPolicy;
use app\iam\domain\Permission;

final class ModuleActionAuthorizer
{
    public function __construct(private readonly LegacyPermissionPolicy $policy = new LegacyPermissionPolicy())
    {
    }

    public function allows(
        RuntimeModule $module,
        Permission $permission,
        LegacyPermissionAssignment $assignment,
        bool $roleDefaultAllows,
        ?string $frame = null,
    ): bool {
        if (!$module->enabled() || $module->definition()->status() !== ModuleLifecycleStatus::ACTIVE) {
            return false;
        }

        return $this->policy->allows($permission, $assignment, $roleDefaultAllows, $frame);
    }
}
