<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use app\admin\middleware\AdminCsrfMiddleware;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use think\Request;

$middleware = new AdminCsrfMiddleware();
$next = static fn (Request $request): string => 'passed';

foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
    $request = (new Request())->setMethod($method);
    expectSame('passed', $middleware->handle($request, $next), $method . ' bypasses csrf validation');
}

foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
    $invalidRequests = [
        'missing cookie' => (new Request())
            ->setMethod($method)
            ->withHeader(['x-csrf-token' => 'csrf-token']),
        'missing header' => (new Request())
            ->setMethod($method)
            ->withCookie(['weplatform_admin_csrf' => 'csrf-token']),
        'blank token' => (new Request())
            ->setMethod($method)
            ->withCookie(['weplatform_admin_csrf' => ''])
            ->withHeader(['x-csrf-token' => '']),
        'mismatch' => (new Request())
            ->setMethod($method)
            ->withCookie(['weplatform_admin_csrf' => 'csrf-cookie-token'])
            ->withHeader(['x-csrf-token' => 'csrf-header-token']),
    ];

    foreach ($invalidRequests as $label => $request) {
        try {
            $middleware->handle($request, $next);
            throw new RuntimeException($method . ' ' . $label . ' must fail csrf validation');
        } catch (AppException $e) {
            expectSame(ErrorCode::FORBIDDEN, $e->errorCode(), $method . ' ' . $label . ' error code');
            expectSame('CSRF validation failed.', $e->getMessage(), $method . ' ' . $label . ' public message');
            expectSame(403, $e->httpStatus(), $method . ' ' . $label . ' http status');
        }
    }

    $valid = (new Request())
        ->setMethod($method)
        ->withCookie(['weplatform_admin_csrf' => 'same-token'])
        ->withHeader(['x-csrf-token' => 'same-token']);
    expectSame('passed', $middleware->handle($valid, $next), $method . ' exact csrf match passes');
}
