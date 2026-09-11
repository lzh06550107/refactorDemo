<?php

declare(strict_types=1);

namespace modules\iam\infrastructure;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\iam\contract\PermissionAuthorizer;
use think\facade\Db;

final class ThinkPhpPermissionAuthorizer implements PermissionAuthorizer
{
    public function assertAllowed(
        string $adminUserId,
        string $tenantId,
        string $permissionKey,
        ?string $accountId = null,
    ): void {
        if (trim($adminUserId) === '' || trim($tenantId) === '' || trim($permissionKey) === '') {
            $this->forbidden();
        }

        if ($this->tenantLevelAllowed($adminUserId, $tenantId, $permissionKey)) {
            return;
        }
        if ($accountId !== null && trim($accountId) !== '' && $this->accountLevelAllowed($adminUserId, $tenantId, $permissionKey, $accountId)) {
            return;
        }

        $this->forbidden();
    }

    private function tenantLevelAllowed(string $adminUserId, string $tenantId, string $permissionKey): bool
    {
        $row = $this->baseQuery($adminUserId, $tenantId, $permissionKey)
            ->whereNull('permission_assignments.account_id')
            ->field('permission_assignments.id')
            ->find();
        return is_array($row);
    }

    private function accountLevelAllowed(string $adminUserId, string $tenantId, string $permissionKey, string $accountId): bool
    {
        $row = $this->baseQuery($adminUserId, $tenantId, $permissionKey)
            ->where('permission_assignments.account_id', $accountId)
            ->field('permission_assignments.id')
            ->find();
        return is_array($row);
    }

    private function baseQuery(string $adminUserId, string $tenantId, string $permissionKey): mixed
    {
        return Db::table('permission_assignments')
            ->join('roles', 'roles.id = permission_assignments.role_id')
            ->join('role_permissions', 'role_permissions.role_id = roles.id')
            ->join('permissions', 'permissions.id = role_permissions.permission_id')
            ->where('permission_assignments.admin_user_id', $adminUserId)
            ->where('permission_assignments.tenant_id', $tenantId)
            ->where('roles.tenant_id', $tenantId)
            ->where('permissions.permission_key', $permissionKey);
    }

    private function forbidden(): never
    {
        throw new AppException(ErrorCode::FORBIDDEN, 'Administrator permission denied.', 403);
    }
}
