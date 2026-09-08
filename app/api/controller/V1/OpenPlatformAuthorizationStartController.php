<?php

declare(strict_types=1);

namespace app\api\controller\V1;

use app\common\context\RequestContext;
use app\common\http\ApiResponse;
use app\openplatform\application\AuthorizationStartService;
use DateTimeImmutable;
use DateTimeZone;
use think\Request;
use think\response\Json;

final readonly class OpenPlatformAuthorizationStartController
{
    public function __construct(
        private AuthorizationStartService $service,
        private RequestContext $context,
    ) {
    }

    public function create(string $componentPlatformId, Request $request): Json
    {
        $result = $this->service->start(
            $componentPlatformId,
            (string) $request->param('tenant_id', ''),
            (string) $request->param('target_account_id', ''),
            (string) $request->param('auth_type', '1'),
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        return ApiResponse::success($this->context, [
            'state' => $result->state(),
            'authorization_url' => $result->authorizationUrl(),
            'expires_at' => $result->expiresAt()->format(DATE_ATOM),
        ]);
    }
}
