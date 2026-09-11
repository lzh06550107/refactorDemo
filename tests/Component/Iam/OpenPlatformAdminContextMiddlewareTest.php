<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use app\api\middleware\OpenPlatformAdminContextMiddleware;
use app\common\context\RequestContext;
use app\common\context\RuntimeType;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\common\security\SecretValue;
use modules\iam\application\RestoreAdminSession;
use modules\iam\contract\AdminSessionRepository;
use modules\iam\contract\AdminTenantAccess;
use modules\iam\domain\AdminSession;
use modules\iam\security\BearerTokenParser;
use modules\iam\security\SessionTokenHasher;
use think\App;
use think\Request;

$hasher = new SessionTokenHasher(new SecretValue('pepper-123'));
$rawToken = 'opaque-session-token';
$session = new AdminSession(
    'session-1',
    'user-1',
    $hasher->hash($rawToken),
    new DateTimeImmutable('2026-09-09T00:00:00+00:00'),
    new DateTimeImmutable('2100-09-09T00:00:00+00:00'),
);
$sessionRepository = new class($session) implements AdminSessionRepository {
    public function __construct(private AdminSession $session) {}
    public function findByTokenHash(string $tokenHash): ?AdminSession
    {
        return hash_equals($this->session->tokenHash(), $tokenHash) ? $this->session : null;
    }
};
$tenantAccess = new class implements AdminTenantAccess {
    public array $calls = [];
    public function assertMember(string $adminUserId, string $tenantId): void
    {
        $this->calls[] = [$adminUserId, $tenantId];
        if ($adminUserId !== 'user-1' || $tenantId !== 'tenant-1') {
            throw new AppException(ErrorCode::FORBIDDEN, 'Tenant membership required.', 403);
        }
    }
};

$app = new App();
$baseContext = new RequestContext(
    'request-1',
    'trace-1',
    RuntimeType::API,
    null,
    null,
    null,
    null,
    'zh-CN',
    '127.0.0.1',
);
$app->instance(RequestContext::class, $baseContext);
$middleware = new OpenPlatformAdminContextMiddleware(
    $app,
    new RestoreAdminSession($sessionRepository, $hasher),
    new BearerTokenParser(),
    $tenantAccess,
);

$validRequest = (new Request())->withHeader([
    'authorization' => 'Bearer ' . $rawToken,
    'x-tenant-id' => 'tenant-1',
]);
$hydrated = $middleware->handle(
    $validRequest,
    static fn (Request $request): RequestContext => $app->make(RequestContext::class),
);

expectSame('request-1', $hydrated->requestId(), 'request id is preserved');
expectSame('trace-1', $hydrated->traceId(), 'trace id is preserved');
expectSame(RuntimeType::API, $hydrated->runtimeType(), 'runtime type is preserved');
expectSame('tenant-1', $hydrated->tenantId(), 'trusted Tenant is hydrated');
expectSame(null, $hydrated->accountId(), 'admin context does not trust an Account selector');
expectSame('user-1', $hydrated->principal()?->id(), 'admin principal id is hydrated from session');
expectSame('admin', $hydrated->principal()?->type(), 'principal type is admin');
expectSame([['user-1', 'tenant-1']], $tenantAccess->calls, 'membership is checked with session user and selected Tenant');

$app->instance(RequestContext::class, $baseContext);
try {
    $middleware->handle(
        (new Request())->withHeader(['x-tenant-id' => 'tenant-1']),
        static fn (Request $request): mixed => null,
    );
    throw new RuntimeException('missing admin session must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::UNAUTHORIZED, $e->errorCode(), 'missing session error code');
    expectSame(401, $e->httpStatus(), 'missing session http status');
}

$app->instance(RequestContext::class, $baseContext);
try {
    $middleware->handle(
        (new Request())->withHeader([
            'authorization' => 'Bearer ' . $rawToken,
            'x-tenant-id' => 'tenant-other',
        ]),
        static fn (Request $request): mixed => null,
    );
    throw new RuntimeException('cross-Tenant admin context must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::FORBIDDEN, $e->errorCode(), 'membership error code');
    expectSame(403, $e->httpStatus(), 'membership error http status');
}
