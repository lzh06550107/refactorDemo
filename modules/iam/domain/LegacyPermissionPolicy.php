<?php

declare(strict_types=1);

namespace modules\iam\domain;

final class LegacyPermissionPolicy
{
    public function allows(
        Permission $permission,
        LegacyPermissionAssignment $assignment,
        bool $roleDefaultAllows,
        ?string $frame = null,
    ): bool {
        if ($assignment->usesRoleDefault()) {
            return $roleDefaultAllows;
        }

        $set = $assignment->permissions();
        if ($set->contains($permission)) {
            return true;
        }

        $frame = trim((string) $frame);
        return $frame !== '' && $set->contains(new Permission($frame . '*'));
    }
}
