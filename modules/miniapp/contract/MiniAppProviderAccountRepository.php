<?php

declare(strict_types=1);

namespace modules\miniapp\contract;

use modules\miniapp\domain\MiniAppProviderAccount;

interface MiniAppProviderAccountRepository
{
    public function findForTenantAccount(string $tenantId, string $accountId): ?MiniAppProviderAccount;
}
