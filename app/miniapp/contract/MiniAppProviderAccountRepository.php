<?php

declare(strict_types=1);

namespace app\miniapp\contract;

use app\miniapp\domain\MiniAppProviderAccount;

interface MiniAppProviderAccountRepository
{
    public function findForTenantAccount(string $tenantId, string $accountId): ?MiniAppProviderAccount;
}
