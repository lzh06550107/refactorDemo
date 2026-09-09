<?php

declare(strict_types=1);

namespace app\iam\infrastructure;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\iam\contract\AdminTenantAccess;
use think\facade\Db;

final class ThinkPhpAdminTenantAccess implements AdminTenantAccess
{
    public function assertMember(string $adminUserId, string $tenantId): void
    {
        if (trim($adminUserId) === '' || trim($tenantId) === '') {
            $this->forbidden();
        }

        $tenant = Db::table('tenants')->where([
            'id' => $tenantId,
            'status' => 'active',
        ])->find();
        if (!is_array($tenant)) {
            $this->forbidden();
        }

        $membership = Db::table('tenant_memberships')->where([
            'tenant_id' => $tenantId,
            'admin_user_id' => $adminUserId,
        ])->find();
        if (!is_array($membership)) {
            $this->forbidden();
        }
    }

    private function forbidden(): never
    {
        throw new AppException(ErrorCode::FORBIDDEN, 'Administrator cannot access the requested Tenant.', 403);
    }
}
