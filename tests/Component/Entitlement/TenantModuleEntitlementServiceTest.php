<?php

declare(strict_types=1);

use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\entitlement\application\TenantModuleEntitlementService;
use modules\entitlement\contract\TenantModuleEntitlementRepository;
use modules\entitlement\domain\ModuleEntitlementSource;
use modules\entitlement\domain\ModuleEntitlementStatus;
use modules\entitlement\domain\TenantModuleEntitlement;

$now = new DateTimeImmutable('2026-09-07T12:00:00+00:00');
$entitlement = new TenantModuleEntitlement(
    'ent-1', 'tenant-1', 'module-1',
    ModuleEntitlementSource::PURCHASE,
    ModuleEntitlementStatus::ACTIVE,
    null,
    null,
);
$repo = new class($entitlement) implements TenantModuleEntitlementRepository {
    public function __construct(private ?TenantModuleEntitlement $value) {}
    public function findForTenantModule(string $tenantId, string $moduleId): ?TenantModuleEntitlement { return $this->value; }
};
$service = new TenantModuleEntitlementService($repo);
expectSame($entitlement, $service->requireActive('tenant-1', 'module-1', $now), 'service must return active entitlement');

$missingRepo = new class implements TenantModuleEntitlementRepository {
    public function findForTenantModule(string $tenantId, string $moduleId): ?TenantModuleEntitlement { return null; }
};
try {
    (new TenantModuleEntitlementService($missingRepo))->requireActive('tenant-1', 'module-1', $now);
    throw new RuntimeException('missing entitlement must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::FORBIDDEN, $e->errorCode(), 'missing entitlement must produce FORBIDDEN');
    expectSame(403, $e->httpStatus(), 'missing entitlement must use HTTP 403');
}
