<?php

declare(strict_types=1);

namespace app\api\controller\V1;

use app\common\context\RequestContext;
use app\common\http\ApiResponse;
use app\openplatform\application\AuthorizationCallbackService;
use DateTimeImmutable;
use DateTimeZone;
use think\Request;
use think\response\Json;

final readonly class OpenPlatformAuthorizationCallbackController
{
    public function __construct(
        private AuthorizationCallbackService $service,
        private RequestContext $context,
    ) {
    }

    public function receive(Request $request): Json
    {
        $result = $this->service->handle(
            (string) $request->get('state', ''),
            (string) $request->get('auth_code', ''),
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $this->context->requestId(),
            $this->context->traceId(),
        );

        return ApiResponse::success($this->context, [
            'status' => $result->status(),
            'authorizer_app_id' => $result->authorizerAppId(),
        ]);
    }
}
