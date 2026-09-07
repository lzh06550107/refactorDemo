<?php

declare(strict_types=1);

namespace app\common\http;

use app\common\context\RequestContext;
use app\common\error\ErrorEnvelope;
use think\response\Json;

final class ApiResponse
{
    public static function success(RequestContext $context, mixed $data = null, string $message = 'ok'): Json
    {
        return json(ErrorEnvelope::success($context->requestId(), $data, $message));
    }

    public static function error(
        RequestContext $context,
        string $code,
        string $message,
        mixed $data = null,
        int $httpStatus = 400,
    ): Json {
        return json(ErrorEnvelope::error($context->requestId(), $code, $message, $data), $httpStatus);
    }
}
