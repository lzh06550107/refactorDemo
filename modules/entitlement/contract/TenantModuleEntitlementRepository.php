<?php

declare(strict_types=1);

namespace modules\entitlement\contract;

use modules\entitlement\domain\TenantModuleEntitlement;

interface TenantModuleEntitlementRepository
{
    public function findForTenantModule(string $tenantId, string $moduleId): ?TenantModuleEntitlement;
}
