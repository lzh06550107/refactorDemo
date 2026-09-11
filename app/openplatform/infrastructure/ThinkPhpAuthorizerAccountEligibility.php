<?php

declare(strict_types=1);

namespace app\openplatform\infrastructure;

use modules\account\domain\AccountType;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\openplatform\contract\AuthorizerAccountEligibility;
use think\facade\Db;

final class ThinkPhpAuthorizerAccountEligibility implements AuthorizerAccountEligibility
{
    public function assertTenantEligible(string $tenantId, string $componentPlatformId): void
    {
        $this->assertActiveTenant($tenantId);
        $this->assertEnabledPlatform($componentPlatformId);
    }

    public function assertExistingAccountEligible(
        string $tenantId,
        string $accountId,
        string $componentPlatformId,
    ): AccountType {
        $this->assertTenantEligible($tenantId, $componentPlatformId);

        $row = Db::table('accounts')->where([
            'id' => $accountId,
            'tenant_id' => $tenantId,
            'status' => 'active',
        ])->find();
        if (!is_array($row)) {
            $this->forbidden();
        }

        $type = AccountType::tryFrom((string) ($row['type'] ?? ''));
        if (!in_array($type, [AccountType::OFFICIAL_ACCOUNT, AccountType::WECHAT_MINI_PROGRAM], true)) {
            $this->forbidden();
        }

        return $type;
    }

    private function assertActiveTenant(string $tenantId): void
    {
        if (trim($tenantId) === '') {
            $this->forbidden();
        }
        $row = Db::table('tenants')->where([
            'id' => $tenantId,
            'status' => 'active',
        ])->find();
        if (!is_array($row)) {
            $this->forbidden();
        }
    }

    private function assertEnabledPlatform(string $componentPlatformId): void
    {
        if (trim($componentPlatformId) === '') {
            $this->forbidden();
        }
        $row = Db::table('component_platforms')->where([
            'id' => $componentPlatformId,
            'enabled' => 1,
        ])->find();
        if (!is_array($row)) {
            $this->forbidden();
        }
    }

    private function forbidden(): never
    {
        throw new AppException(ErrorCode::FORBIDDEN, 'OpenPlatform authorization target is not eligible.', 403);
    }
}
