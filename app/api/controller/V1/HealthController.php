<?php

declare(strict_types=1);

namespace app\api\controller\V1;

use app\common\context\RequestContext;
use app\common\http\ApiResponse;
use think\response\Json;

final class HealthController
{
    public function __construct(private readonly RequestContext $context)
    {
    }

    public function index(): Json
    {
        return ApiResponse::success($this->context, ['application' => 'api']);
    }
}
