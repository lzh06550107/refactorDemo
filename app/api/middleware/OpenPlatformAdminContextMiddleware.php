<?php

declare(strict_types=1);

namespace app\api\middleware;

use app\common\context\Principal;
use app\common\context\RequestContext;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\iam\application\RestoreAdminSession;
use modules\iam\contract\AdminTenantAccess;
use modules\iam\security\BearerTokenParser;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use think\App;
use think\Request;

final readonly class OpenPlatformAdminContextMiddleware
{
    public function __construct(
        private App $app,
        private RestoreAdminSession $restoreSession,
        private BearerTokenParser $bearerTokens,
        private AdminTenantAccess $tenantAccess,
    ) {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $base = $this->app->make(RequestContext::class);
        $rawToken = $this->bearerTokens->parse((string) $request->header('authorization', ''));
        $session = $this->restoreSession->execute(
            $rawToken,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        $tenantId = trim((string) $request->header('x-tenant-id', ''));
        if ($tenantId === '') {
            throw new AppException(ErrorCode::FORBIDDEN, 'Trusted Tenant selector is required.', 403);
        }
        $this->tenantAccess->assertMember($session->userId(), $tenantId);

        $this->app->instance(RequestContext::class, new RequestContext(
            requestId: $base->requestId(),
            traceId: $base->traceId(),
            runtimeType: $base->runtimeType(),
            tenantId: $tenantId,
            accountId: null,
            siteId: $base->siteId(),
            principal: new Principal($session->userId(), 'admin'),
            locale: $base->locale(),
            clientIp: $base->clientIp(),
        ));

        return $next($request);
    }
}
