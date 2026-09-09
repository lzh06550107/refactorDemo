<?php

declare(strict_types=1);

namespace app\openplatform\infrastructure;

use app\account\domain\AccountType;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\openplatform\contract\AuthorizerConnectionStore;
use app\openplatform\domain\AuthorizerAccountOwnership;
use DateTimeImmutable;
use think\facade\Db;

final readonly class ThinkPhpAuthorizerConnectionStore implements AuthorizerConnectionStore
{
    public function enableExisting(AuthorizerAccountOwnership $ownership, DateTimeImmutable $now): void
    {
        Db::transaction(function () use ($ownership): void {
            $table = $this->tableFor($ownership->accountType());
            $current = Db::table($table)->where('account_id', $ownership->accountId())->lock(true)->find();
            $this->assertCompatible($current, $ownership);

            $row = [
                'tenant_id' => $ownership->tenantId(),
                'provider_app_id' => $ownership->authorizerAppId(),
                'connection_mode' => 'component',
                'credential_ref' => null,
                'component_platform_id' => $ownership->componentPlatformId(),
                'enabled' => 1,
            ];
            if (is_array($current)) {
                Db::table($table)->where('account_id', $ownership->accountId())->update($row);
                return;
            }

            Db::table($table)->insert(['account_id' => $ownership->accountId()] + $row);
        });
    }

    public function disable(string $componentPlatformId, string $authorizerAppId, DateTimeImmutable $now): void
    {
        Db::transaction(function () use ($componentPlatformId, $authorizerAppId): void {
            $ownership = Db::table('authorizer_account_ownerships')->where([
                'component_platform_id' => $componentPlatformId,
                'authorizer_app_id' => $authorizerAppId,
            ])->lock(true)->find();
            if (!is_array($ownership)) {
                return;
            }

            $accountType = AccountType::tryFrom((string) ($ownership['account_type'] ?? ''));
            if ($accountType === null) {
                return;
            }
            Db::table($this->tableFor($accountType))->where('account_id', (string) $ownership['account_id'])->update([
                'enabled' => 0,
            ]);
        });
    }

    private function tableFor(AccountType $accountType): string
    {
        return match ($accountType) {
            AccountType::WECHAT_MINI_PROGRAM => 'miniapp_provider_accounts',
            AccountType::OFFICIAL_ACCOUNT => 'official_account_provider_accounts',
            default => throw new AppException(ErrorCode::FORBIDDEN, 'Unsupported OpenPlatform Account type.', 403),
        };
    }

    private function assertCompatible(mixed $current, AuthorizerAccountOwnership $ownership): void
    {
        if (!is_array($current)) {
            return;
        }

        $providerAppId = (string) ($current['provider_app_id'] ?? '');
        $componentPlatformId = $current['component_platform_id'] ?? null;
        if (
            ($providerAppId !== '' && !hash_equals($providerAppId, $ownership->authorizerAppId()))
            || ($componentPlatformId !== null && (string) $componentPlatformId !== '' && !hash_equals((string) $componentPlatformId, $ownership->componentPlatformId()))
        ) {
            throw new AppException(ErrorCode::CONFLICT, 'OpenPlatform Account connection belongs to another authorizer.', 409);
        }
    }
}
