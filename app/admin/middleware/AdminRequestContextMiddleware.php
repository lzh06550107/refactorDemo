<?php

declare(strict_types=1);

namespace app\admin\middleware;

use app\common\context\RuntimeType;
use app\common\middleware\RequestContextMiddleware;
use Closure;
use think\Request;

final class AdminRequestContextMiddleware
{
    public function __construct(private readonly RequestContextMiddleware $middleware)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        return $this->middleware->handle($request, $next, RuntimeType::ADMIN);
    }
}
