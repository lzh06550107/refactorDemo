<?php

declare(strict_types=1);

namespace app\openplatform\application;

use app\common\context\RequestContext;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\iam\contract\PermissionAuthorizer;
use app\openplatform\domain\OpenPlatformPermission;

final readonly class OpenPlatformAdminGuard
{
    public function __construct(
        private RequestContext $context,
        private PermissionAuthorizer $permissions,
    ) {
    }

    public function require(OpenPlatformPermission $permission, ?string $accountId = null): void
    {
        $tenantId = $this->context->tenantId();
        $principal = $this->context->principal();
        if ($tenantId === null || trim($tenantId) === '' || $principal === null) {
            throw new AppException(ErrorCode::UNAUTHORIZED, 'Trusted administrator context is required.', 401);
        }

        $this->permissions->assertAllowed(
            $principal->id(),
            $tenantId,
            $permission->value,
            $accountId,
        );
    }
}
