<?php

declare(strict_types=1);

use app\entitlement\domain\ModuleEntitlementSource;
use app\entitlement\domain\ModuleEntitlementStatus;
use app\entitlement\domain\TenantModuleEntitlement;

$now = new DateTimeImmutable('2026-09-07T12:00:00+00:00');
$active = new TenantModuleEntitlement(
    'ent-1', 'tenant-1', 'module-1',
    ModuleEntitlementSource::PLATFORM_GRANT,
    ModuleEntitlementStatus::ACTIVE,
    $now->modify('-1 hour'),
    $now->modify('+1 hour'),
);
expectTrue($active->isActiveAt($now), 'active entitlement must be usable inside its time window');

$suspended = new TenantModuleEntitlement(
    'ent-2', 'tenant-1', 'module-1',
    ModuleEntitlementSource::MANUAL_GRANT,
    ModuleEntitlementStatus::SUSPENDED,
    null,
    null,
);
expectTrue(!$suspended->isActiveAt($now), 'suspended entitlement must deny access');

$future = new TenantModuleEntitlement(
    'ent-3', 'tenant-1', 'module-1',
    ModuleEntitlementSource::TRIAL,
    ModuleEntitlementStatus::ACTIVE,
    $now->modify('+1 second'),
    null,
);
expectTrue(!$future->isActiveAt($now), 'entitlement must not be active before startsAt');

expectThrows(
    static fn () => new TenantModuleEntitlement(
        'ent-4', 'tenant-1', 'module-1',
        ModuleEntitlementSource::PURCHASE,
        ModuleEntitlementStatus::ACTIVE,
        $now,
        $now->modify('-1 second'),
    ),
    InvalidArgumentException::class,
    'entitlement must reject an inverted validity window',
);
