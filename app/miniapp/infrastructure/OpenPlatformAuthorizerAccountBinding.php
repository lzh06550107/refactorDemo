<?php

declare(strict_types=1);

namespace app\miniapp\infrastructure;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\openplatform\contract\AuthorizerAccountBinding;
use think\facade\Db;

final class OpenPlatformAuthorizerAccountBinding implements AuthorizerAccountBinding
{
    public function bindExistingAccount(
        string $tenantId,
        string $accountId,
        string $componentPlatformId,
        string $authorizerAppId,
    ): void {
        Db::transaction(function () use ($tenantId, $accountId, $componentPlatformId, $authorizerAppId): void {
            $tenant = Db::table('tenants')->where('id', $tenantId)->lock(true)->find();
            if (!is_array($tenant) || (string) ($tenant['status'] ?? '') !== 'active') {
                $this->forbidden();
            }

            $account = Db::table('accounts')->where([
                'id' => $accountId,
                'tenant_id' => $tenantId,
            ])->lock(true)->find();
            if (
                !is_array($account)
                || (string) ($account['status'] ?? '') !== 'active'
                || (string) ($account['type'] ?? '') !== 'wechat_mini_program'
            ) {
                $this->forbidden();
            }

            $platform = Db::table('component_platforms')->where('id', $componentPlatformId)->lock(true)->find();
            if (!is_array($platform) || (int) ($platform['enabled'] ?? 0) !== 1) {
                $this->forbidden();
            }

            $current = Db::table('miniapp_provider_accounts')->where('account_id', $accountId)->lock(true)->find();
            $owner = Db::table('miniapp_provider_accounts')->where([
                'tenant_id' => $tenantId,
                'provider_app_id' => $authorizerAppId,
            ])->lock(true)->find();
            if (is_array($owner) && (string) $owner['account_id'] !== $accountId) {
                $this->forbidden();
            }

            if (is_array($current)) {
                $currentProviderAppId = (string) ($current['provider_app_id'] ?? '');
                $currentPlatformId = $current['component_platform_id'] ?? null;
                if (
                    ($currentProviderAppId !== '' && !hash_equals($currentProviderAppId, $authorizerAppId))
                    || ($currentPlatformId !== null && (string) $currentPlatformId !== '' && !hash_equals((string) $currentPlatformId, $componentPlatformId))
                ) {
                    $this->forbidden();
                }

                Db::table('miniapp_provider_accounts')->where('account_id', $accountId)->update([
                    'tenant_id' => $tenantId,
                    'provider_app_id' => $authorizerAppId,
                    'connection_mode' => 'component',
                    'credential_ref' => null,
                    'component_platform_id' => $componentPlatformId,
                    'enabled' => 1,
                ]);
                return;
            }

            Db::table('miniapp_provider_accounts')->insert([
                'account_id' => $accountId,
                'tenant_id' => $tenantId,
                'provider_app_id' => $authorizerAppId,
                'connection_mode' => 'component',
                'credential_ref' => null,
                'component_platform_id' => $componentPlatformId,
                'enabled' => 1,
            ]);
        });
    }

    private function forbidden(): never
    {
        throw new AppException(ErrorCode::FORBIDDEN, 'OpenPlatform authorizer cannot be bound to the requested Account.', 403);
    }
}
