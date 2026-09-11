<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use app\admin\middleware\AdminSessionCookieMiddleware;
use app\common\context\RequestContext;
use app\common\context\RuntimeType;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\common\security\SecretValue;
use modules\iam\application\RestoreAdminSession;
use modules\iam\contract\AdminSessionRepository;
use modules\iam\domain\AdminSession;
use modules\iam\security\SessionTokenHasher;
use think\App;
use think\Request;

$hasher = new SessionTokenHasher(new SecretValue('cookie-session-pepper'));
$rawToken = 'browser-admin-session-token';
$session = new AdminSession(
    'session-cookie-1',
    'admin-user-1',
    $hasher->hash($rawToken),
    new DateTimeImmutable('2026-09-11T00:00:00+00:00'),
    new DateTimeImmutable('2100-09-11T00:00:00+00:00'),
);
$repository = new class($session) implements AdminSessionRepository {
    public function __construct(private AdminSession $session)
    {
    }

    public function findByTokenHash(string $tokenHash): ?AdminSession
    {
        return hash_equals($this->session->tokenHash(), $tokenHash) ? $this->session : null;
    }
};

$app = new App();
$baseContext = new RequestContext(
    'request-cookie-1',
    'trace-cookie-1',
    RuntimeType::ADMIN,
    'tenant-existing',
    'account-existing',
    'site-existing',
    null,
    'zh-CN',
    '127.0.0.2',
);
$app->instance(RequestContext::class, $baseContext);
$middleware = new AdminSessionCookieMiddleware(
    $app,
    new RestoreAdminSession($repository, $hasher),
);

$validRequest = (new Request())->withCookie([
    'weplatform_admin_session' => $rawToken,
]);
$hydrated = $middleware->handle(
    $validRequest,
    static fn (Request $request): RequestContext => $app->make(RequestContext::class),
);

expectSame('request-cookie-1', $hydrated->requestId(), 'request id is preserved');
expectSame('trace-cookie-1', $hydrated->traceId(), 'trace id is preserved');
expectSame(RuntimeType::ADMIN, $hydrated->runtimeType(), 'runtime type is preserved');
expectSame('tenant-existing', $hydrated->tenantId(), 'tenant context is preserved');
expectSame('account-existing', $hydrated->accountId(), 'account context is preserved');
expectSame('site-existing', $hydrated->siteId(), 'site context is preserved');
expectSame('zh-CN', $hydrated->locale(), 'locale is preserved');
expectSame('127.0.0.2', $hydrated->clientIp(), 'client ip is preserved');
expectSame('admin-user-1', $hydrated->principal()?->id(), 'admin principal id comes from restored session');
expectSame('admin', $hydrated->principal()?->type(), 'principal type is admin');

$app->instance(RequestContext::class, $baseContext);
try {
    $middleware->handle(
        new Request(),
        static fn (Request $request): mixed => null,
    );
    throw new RuntimeException('missing browser admin session cookie must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::UNAUTHORIZED, $e->errorCode(), 'missing cookie error code');
    expectSame(401, $e->httpStatus(), 'missing cookie http status');
}

$app->instance(RequestContext::class, $baseContext);
try {
    $middleware->handle(
        (new Request())->withHeader(['authorization' => 'Bearer ' . $rawToken]),
        static fn (Request $request): mixed => null,
    );
    throw new RuntimeException('browser admin middleware must not accept bearer fallback');
} catch (AppException $e) {
    expectSame(ErrorCode::UNAUTHORIZED, $e->errorCode(), 'bearer fallback remains unauthorized');
    expectSame(401, $e->httpStatus(), 'bearer fallback http status');
}
