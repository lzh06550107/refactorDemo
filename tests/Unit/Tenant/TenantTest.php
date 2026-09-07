<?php

declare(strict_types=1);

use app\tenant\domain\Tenant;
use app\tenant\domain\TenantMembership;
use app\tenant\domain\TenantRole;
use app\tenant\domain\TenantStatus;

$tenant = new Tenant('tenant-1', 'Acme', TenantStatus::ACTIVE);
expectSame('tenant-1', $tenant->id(), 'tenant id');
expectSame('Acme', $tenant->name(), 'tenant name');
expectSame(TenantStatus::ACTIVE, $tenant->status(), 'tenant status');

$membership = new TenantMembership('tenant-1', 'user-7', TenantRole::ADMIN);
expectSame('tenant-1', $membership->tenantId(), 'membership tenant');
expectSame('user-7', $membership->userId(), 'membership user');
expectSame(TenantRole::ADMIN, $membership->role(), 'membership role');

expectThrows(fn () => new Tenant('', 'Acme', TenantStatus::ACTIVE), InvalidArgumentException::class, 'tenant id required');
expectThrows(fn () => new Tenant('tenant-1', '', TenantStatus::ACTIVE), InvalidArgumentException::class, 'tenant name required');
expectThrows(fn () => new TenantMembership('', 'user-7', TenantRole::MEMBER), InvalidArgumentException::class, 'membership tenant required');
