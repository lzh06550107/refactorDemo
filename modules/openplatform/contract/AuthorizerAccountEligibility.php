<?php

declare(strict_types=1);

namespace modules\openplatform\contract;

use modules\account\domain\AccountType;

interface AuthorizerAccountEligibility
{
    public function assertTenantEligible(string $tenantId, string $componentPlatformId): void;

    public function assertExistingAccountEligible(
        string $tenantId,
        string $accountId,
        string $componentPlatformId,
    ): AccountType;
}
