<?php

declare(strict_types=1);

namespace modules\iam\contract;

interface PermissionAuthorizer
{
    public function assertAllowed(
        string $adminUserId,
        string $tenantId,
        string $permissionKey,
        ?string $accountId = null,
    ): void;
}
