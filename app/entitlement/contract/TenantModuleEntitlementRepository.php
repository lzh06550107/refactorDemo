<?php

declare(strict_types=1);

namespace app\entitlement\contract;

use app\entitlement\domain\TenantModuleEntitlement;

interface TenantModuleEntitlementRepository
{
    public function findForTenantModule(string $tenantId, string $moduleId): ?TenantModuleEntitlement;
}
