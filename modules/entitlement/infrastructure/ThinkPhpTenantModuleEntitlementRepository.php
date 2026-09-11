<?php

declare(strict_types=1);

namespace modules\entitlement\infrastructure;

use modules\entitlement\contract\TenantModuleEntitlementRepository;
use modules\entitlement\domain\ModuleEntitlementSource;
use modules\entitlement\domain\ModuleEntitlementStatus;
use modules\entitlement\domain\TenantModuleEntitlement;
use DateTimeImmutable;
use think\facade\Db;

final class ThinkPhpTenantModuleEntitlementRepository implements TenantModuleEntitlementRepository
{
    public function findForTenantModule(string $tenantId, string $moduleId): ?TenantModuleEntitlement
    {
        $row = Db::table('tenant_module_entitlements')
            ->where(['tenant_id' => $tenantId, 'module_id' => $moduleId])
            ->order('created_at', 'desc')
            ->find();
        if ($row === null) {
            return null;
        }
        $row = (array) $row;
        return new TenantModuleEntitlement(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            moduleId: (string) $row['module_id'],
            source: ModuleEntitlementSource::from((string) $row['source']),
            status: ModuleEntitlementStatus::from((string) $row['status']),
            startsAt: $this->date($row['starts_at'] ?? null),
            endsAt: $this->date($row['ends_at'] ?? null),
        );
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        return $value === null || $value === '' ? null : new DateTimeImmutable((string) $value);
    }
}
