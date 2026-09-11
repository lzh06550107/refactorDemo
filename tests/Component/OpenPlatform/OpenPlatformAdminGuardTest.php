<?php

declare(strict_types=1);

use app\common\context\Principal;
use app\common\context\RequestContext;
use app\common\context\RuntimeType;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\iam\contract\PermissionAuthorizer;
use app\openplatform\application\OpenPlatformAdminGuard;
use app\openplatform\domain\OpenPlatformPermission;

$authorizer = new class implements PermissionAuthorizer {
    public array $calls = [];
    public bool $deny = false;

    public function assertAllowed(
        string $adminUserId,
        string $tenantId,
        string $permissionKey,
        ?string $accountId = null,
    ): void {
        $this->calls[] = [$adminUserId, $tenantId, $permissionKey, $accountId];
        if ($this->deny) {
            throw new AppException(ErrorCode::FORBIDDEN, 'Permission denied.', 403);
        }
    }
};

$context = new RequestContext(
    'request-1',
    'trace-1',
    RuntimeType::API,
    'tenant-1',
    null,
    null,
    new Principal('user-1', 'admin'),
    'zh-CN',
    '127.0.0.1',
);
$guard = new OpenPlatformAdminGuard($context, $authorizer);
$guard->require(OpenPlatformPermission::PROVISION);
expectSame(
    [['user-1', 'tenant-1', 'openplatform.authorizer.provision', null]],
    $authorizer->calls,
    'guard authorizes trusted principal and Tenant using stable permission key',
);

$authorizer->deny = true;
try {
    $guard->require(OpenPlatformPermission::READ, 'account-1');
    throw new RuntimeException('permission denial must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::FORBIDDEN, $e->errorCode(), 'permission denial error code');
    expectSame(403, $e->httpStatus(), 'permission denial http status');
}
expectSame(
    ['user-1', 'tenant-1', 'openplatform.authorizer.read', 'account-1'],
    $authorizer->calls[1],
    'account-scoped permission check receives exact Account id',
);

$unauthenticated = new RequestContext(
    'request-2',
    'trace-2',
    RuntimeType::API,
    null,
    null,
    null,
    null,
    'zh-CN',
    '127.0.0.1',
);
try {
    (new OpenPlatformAdminGuard($unauthenticated, $authorizer))->require(OpenPlatformPermission::READ);
    throw new RuntimeException('missing trusted admin context must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::UNAUTHORIZED, $e->errorCode(), 'missing trusted context error code');
    expectSame(401, $e->httpStatus(), 'missing trusted context http status');
}
