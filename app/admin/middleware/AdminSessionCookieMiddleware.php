<?php

declare(strict_types=1);

namespace app\admin\middleware;

use app\common\context\Principal;
use app\common\context\RequestContext;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use modules\iam\application\RestoreAdminSession;
use think\App;
use think\Request;

final readonly class AdminSessionCookieMiddleware
{
    public function __construct(
        private App $app,
        private RestoreAdminSession $restoreAdminSession,
    ) {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $rawToken = (string) $request->cookie('weplatform_admin_session', '');
        $session = $this->restoreAdminSession->execute(
            $rawToken,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        $base = $this->app->make(RequestContext::class);
        $this->app->instance(RequestContext::class, new RequestContext(
            $base->requestId(),
            $base->traceId(),
            $base->runtimeType(),
            $base->tenantId(),
            $base->accountId(),
            $base->siteId(),
            new Principal($session->userId(), 'admin'),
            $base->locale(),
            $base->clientIp(),
        ));

        return $next($request);
    }
}
