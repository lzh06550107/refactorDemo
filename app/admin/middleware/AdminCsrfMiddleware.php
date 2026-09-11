<?php

declare(strict_types=1);

namespace app\admin\middleware;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use Closure;
use think\Request;

final class AdminCsrfMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $cookieToken = (string) $request->cookie('weplatform_admin_csrf', '');
        $headerToken = (string) $request->header('x-csrf-token', '');

        if ($cookieToken === '' || $headerToken === '' || !hash_equals($cookieToken, $headerToken)) {
            throw new AppException(ErrorCode::FORBIDDEN, 'CSRF validation failed.', 403);
        }

        return $next($request);
    }
}
