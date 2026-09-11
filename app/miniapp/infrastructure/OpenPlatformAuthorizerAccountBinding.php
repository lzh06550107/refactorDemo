<?php

declare(strict_types=1);

namespace app\miniapp\infrastructure;

use modules\account\domain\AccountType;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\openplatform\contract\AuthorizerAccountBinding;
use DateTimeImmutable;
use DateTimeZone;
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
            $accountType = is_array($account) ? (string) ($account['type'] ?? '') : '';
            if (
                !is_array($account)
                || (string) ($account['status'] ?? '') !== 'active'
                || !in_array($accountType, [
                    AccountType::WECHAT_MINI_PROGRAM->value,
                    AccountType::OFFICIAL_ACCOUNT->value,
                ], true)
            ) {
                $this->forbidden();
            }

            $platform = Db::table('component_platforms')->where('id', $componentPlatformId)->lock(true)->find();
            if (!is_array($platform) || (int) ($platform['enabled'] ?? 0) !== 1) {
                $this->forbidden();
            }

            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $canonical = Db::table('authorizer_account_ownerships')->where([
                'component_platform_id' => $componentPlatformId,
                'authorizer_app_id' => $authorizerAppId,
            ])->lock(true)->find();

            if (is_array($canonical)) {
                if (
                    !hash_equals((string) $canonical['tenant_id'], $tenantId)
                    || !hash_equals((string) $canonical['account_id'], $accountId)
                    || !hash_equals((string) $canonical['account_type'], $accountType)
                ) {
                    $this->conflict();
                }
                Db::table('authorizer_account_ownerships')->where([
                    'component_platform_id' => $componentPlatformId,
                    'authorizer_app_id' => $authorizerAppId,
                ])->update([
                    'last_connected_at' => $this->sqlDate($now),
                ]);
            } else {
                $accountOwner = Db::table('authorizer_account_ownerships')->where('account_id', $accountId)->lock(true)->find();
                if (is_array($accountOwner)) {
                    $this->conflict();
                }

                Db::table('authorizer_account_ownerships')->insert([
                    'component_platform_id' => $componentPlatformId,
                    'authorizer_app_id' => $authorizerAppId,
                    'tenant_id' => $tenantId,
                    'account_id' => $accountId,
                    'account_type' => $accountType,
                    'first_bound_at' => $this->sqlDate($now),
                    'last_connected_at' => $this->sqlDate($now),
                ]);
            }

            if ($accountType === AccountType::WECHAT_MINI_PROGRAM->value) {
                $this->enableMiniProgramConnection($tenantId, $accountId, $componentPlatformId, $authorizerAppId);
                return;
            }

            $this->enableOfficialAccountConnection($tenantId, $accountId, $componentPlatformId, $authorizerAppId);
        });
    }

    private function enableMiniProgramConnection(
        string $tenantId,
        string $accountId,
        string $componentPlatformId,
        string $authorizerAppId,
    ): void {
        $current = Db::table('miniapp_provider_accounts')->where('account_id', $accountId)->lock(true)->find();
        $providerOwner = Db::table('miniapp_provider_accounts')->where([
            'tenant_id' => $tenantId,
            'provider_app_id' => $authorizerAppId,
        ])->lock(true)->find();
        $this->assertConnectionCompatible($current, $providerOwner, $accountId, $componentPlatformId, $authorizerAppId);
        $this->writeConnection('miniapp_provider_accounts', $current, $tenantId, $accountId, $componentPlatformId, $authorizerAppId);
    }

    private function enableOfficialAccountConnection(
        string $tenantId,
        string $accountId,
        string $componentPlatformId,
        string $authorizerAppId,
    ): void {
        $current = Db::table('official_account_provider_accounts')->where('account_id', $accountId)->lock(true)->find();
        $providerOwner = Db::table('official_account_provider_accounts')->where([
            'tenant_id' => $tenantId,
            'provider_app_id' => $authorizerAppId,
        ])->lock(true)->find();
        $this->assertConnectionCompatible($current, $providerOwner, $accountId, $componentPlatformId, $authorizerAppId);
        $this->writeConnection('official_account_provider_accounts', $current, $tenantId, $accountId, $componentPlatformId, $authorizerAppId);
    }

    private function assertConnectionCompatible(
        mixed $current,
        mixed $providerOwner,
        string $accountId,
        string $componentPlatformId,
        string $authorizerAppId,
    ): void {
        if (is_array($providerOwner) && !hash_equals((string) $providerOwner['account_id'], $accountId)) {
            $this->conflict();
        }
        if (!is_array($current)) {
            return;
        }

        $currentProviderAppId = (string) ($current['provider_app_id'] ?? '');
        $currentPlatformId = $current['component_platform_id'] ?? null;
        if (
            ($currentProviderAppId !== '' && !hash_equals($currentProviderAppId, $authorizerAppId))
            || ($currentPlatformId !== null && (string) $currentPlatformId !== '' && !hash_equals((string) $currentPlatformId, $componentPlatformId))
        ) {
            $this->conflict();
        }
    }

    private function writeConnection(
        string $table,
        mixed $current,
        string $tenantId,
        string $accountId,
        string $componentPlatformId,
        string $authorizerAppId,
    ): void {
        $row = [
            'tenant_id' => $tenantId,
            'provider_app_id' => $authorizerAppId,
            'connection_mode' => 'component',
            'credential_ref' => null,
            'component_platform_id' => $componentPlatformId,
            'enabled' => 1,
        ];
        if (is_array($current)) {
            Db::table($table)->where('account_id', $accountId)->update($row);
            return;
        }
        Db::table($table)->insert(['account_id' => $accountId] + $row);
    }

    private function forbidden(): never
    {
        throw new AppException(ErrorCode::FORBIDDEN, 'OpenPlatform authorizer cannot be bound to the requested Account.', 403);
    }

    private function conflict(): never
    {
        throw new AppException(ErrorCode::CONFLICT, 'OpenPlatform authorizer is owned by another Tenant or Account.', 409);
    }

    private function sqlDate(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.u');
    }
}
