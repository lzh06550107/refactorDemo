<?php

declare(strict_types=1);

namespace app\miniapp\infrastructure;

use app\miniapp\contract\MiniAppProviderAccountRepository;
use app\miniapp\domain\MiniAppConnectionMode;
use app\miniapp\domain\MiniAppProviderAccount;
use think\facade\Db;

final class ThinkPhpMiniAppProviderAccountRepository implements MiniAppProviderAccountRepository
{
    public function findForTenantAccount(string $tenantId, string $accountId): ?MiniAppProviderAccount
    {
        $row = Db::table('miniapp_provider_accounts')->where([
            'tenant_id' => $tenantId,
            'account_id' => $accountId,
            'enabled' => 1,
        ])->find();

        if ($row === null) {
            return null;
        }
        $row = (array) $row;

        return new MiniAppProviderAccount(
            (string) $row['tenant_id'],
            (string) $row['account_id'],
            (string) $row['provider_app_id'],
            MiniAppConnectionMode::from((string) $row['connection_mode']),
            $this->nullableString($row['credential_ref'] ?? null),
            $this->nullableString($row['component_platform_id'] ?? null),
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
