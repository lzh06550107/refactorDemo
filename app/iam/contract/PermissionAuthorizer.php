<?php

declare(strict_types=1);

namespace app\iam\contract;

interface PermissionAuthorizer
{
    public function assertAllowed(
        string $adminUserId,
        string $tenantId,
        string $permissionKey,
        ?string $accountId = null,
    ): void;
}
