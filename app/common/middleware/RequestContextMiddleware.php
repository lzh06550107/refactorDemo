<?php

declare(strict_types=1);

namespace app\common\middleware;

use app\common\context\RequestContext;
use app\common\context\RequestContextFactory;
use app\common\context\RuntimeType;
use Closure;
use think\App;
use think\Request;

final class RequestContextMiddleware
{
    public function __construct(
        private readonly App $app,
        private readonly RequestContextFactory $factory,
    ) {
    }

    public function handle(Request $request, Closure $next, RuntimeType|string $runtimeType): mixed
    {
        $type = $runtimeType instanceof RuntimeType ? $runtimeType : RuntimeType::from($runtimeType);
        $context = $this->factory->fromRequest($request, $type);
        $this->app->instance(RequestContext::class, $context);

        return $next($request);
    }
}
