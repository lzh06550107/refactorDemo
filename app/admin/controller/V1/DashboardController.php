<?php

declare(strict_types=1);

namespace app\admin\controller\V1;

use app\common\context\RequestContext;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\common\http\ApiResponse;
use think\response\Json;

final readonly class DashboardController
{
    public function __construct(private RequestContext $context)
    {
    }

    public function index(): Json
    {
        $principal = $this->context->principal();
        if ($principal === null || $principal->type() !== 'admin') {
            throw new AppException(
                ErrorCode::UNAUTHORIZED,
                'Administrator session is invalid or expired.',
                401,
            );
        }

        return ApiResponse::success($this->context, [
            'application' => 'admin',
            'status' => 'ready',
            'admin_user_id' => $principal->id(),
        ]);
    }
}
