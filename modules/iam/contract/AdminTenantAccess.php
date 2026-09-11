<?php

declare(strict_types=1);

namespace modules\iam\contract;

interface AdminTenantAccess
{
    public function assertMember(string $adminUserId, string $tenantId): void;
}
