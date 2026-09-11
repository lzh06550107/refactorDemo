<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 3) . '/vendor/topthink/framework/src/helper.php';

use app\admin\controller\V1\DashboardController;
use app\common\context\Principal;
use app\common\context\RequestContext;
use app\common\context\RuntimeType;
use app\common\error\AppException;
use app\common\error\ErrorCode;

$anonymousContext = new RequestContext(
    'request-dashboard-anonymous',
    'trace-dashboard-anonymous',
    RuntimeType::ADMIN,
    null,
    null,
    null,
    null,
    'zh-CN',
    '127.0.0.1',
);

try {
    (new DashboardController($anonymousContext))->index();
    throw new RuntimeException('anonymous dashboard request must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::UNAUTHORIZED, $e->errorCode(), 'anonymous dashboard error code');
    expectSame(401, $e->httpStatus(), 'anonymous dashboard http status');
}

$authenticatedContext = new RequestContext(
    'request-dashboard-authenticated',
    'trace-dashboard-authenticated',
    RuntimeType::ADMIN,
    null,
    null,
    null,
    new Principal('admin-dashboard-1', 'admin'),
    'zh-CN',
    '127.0.0.1',
);
$response = (new DashboardController($authenticatedContext))->index();
expectSame(200, $response->getCode(), 'authenticated dashboard status');
expectSame([
    'application' => 'admin',
    'status' => 'ready',
    'admin_user_id' => 'admin-dashboard-1',
], $response->getData()['data'] ?? null, 'dashboard response is the minimal landing summary');

$root = dirname(__DIR__, 3);
$routeFile = $root . '/app/admin/route/app.php';
$routes = (string) file_get_contents($routeFile);
expectTrue(
    str_contains($routes, "Route::get('v1/dashboard', 'V1.DashboardController/index')"),
    'dashboard route is registered',
);
$dashboardRoutePosition = strpos($routes, "Route::get('v1/dashboard', 'V1.DashboardController/index')");
expectTrue($dashboardRoutePosition !== false, 'dashboard route position is available');
$dashboardRoute = substr($routes, $dashboardRoutePosition, 220);
expectTrue(
    str_contains($dashboardRoute, 'AdminSessionCookieMiddleware::class'),
    'dashboard route requires browser admin session middleware',
);
