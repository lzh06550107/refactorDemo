<?php

declare(strict_types=1);

namespace app\entitlement\application;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\entitlement\contract\TenantModuleEntitlementRepository;
use app\entitlement\domain\TenantModuleEntitlement;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class TenantModuleEntitlementService
{
    public function __construct(private TenantModuleEntitlementRepository $repository) {}

    public function requireActive(string $tenantId, string $moduleId, DateTimeImmutable $at): TenantModuleEntitlement
    {
        if (trim($tenantId) === '' || trim($moduleId) === '') {
            throw new InvalidArgumentException('tenantId and moduleId must not be empty.');
        }
        $entitlement = $this->repository->findForTenantModule($tenantId, $moduleId);
        if ($entitlement === null || !$entitlement->isActiveAt($at)) {
            throw new AppException(ErrorCode::FORBIDDEN, 'Module entitlement is not active.', 403, [
                'tenant_id' => $tenantId,
                'module_id' => $moduleId,
            ]);
        }
        return $entitlement;
    }
}
