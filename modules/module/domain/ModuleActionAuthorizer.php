<?php

declare(strict_types=1);

namespace modules\module\domain;

use modules\iam\domain\LegacyPermissionAssignment;
use modules\iam\domain\LegacyPermissionPolicy;
use modules\iam\domain\Permission;

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
