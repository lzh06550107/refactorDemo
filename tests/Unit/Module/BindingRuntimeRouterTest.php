<?php

declare(strict_types=1);

use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\module\domain\BindingRouteKind;
use app\module\domain\BindingRuntimeRouter;
use app\module\domain\ModuleBinding;
use app\module\domain\ModuleBindingType;

$router = new BindingRuntimeRouter();
$bindings = [
    new ModuleBinding('demo', ModuleBindingType::MENU, 'orders', 'Orders', '/module/demo/orders'),
    new ModuleBinding('demo', ModuleBindingType::MENU, 'dynamic', 'Dynamic', null, 'buildMenu'),
];

$static = $router->resolve($bindings, 'demo', ModuleBindingType::MENU, 'orders');
expectSame(BindingRouteKind::NEW_RUNTIME, $static->kind(), 'static binding must route to new runtime');
expectSame('/module/demo/orders', $static->routePath(), 'static binding must preserve route path');

$dynamic = $router->resolve($bindings, 'demo', ModuleBindingType::MENU, 'dynamic');
expectSame(BindingRouteKind::LEGACY_DELEGATE, $dynamic->kind(), 'R20 call binding must explicitly delegate to legacy runtime');
expectSame('buildMenu', $dynamic->legacyCall(), 'legacy callback name must be preserved as metadata');

try {
    $router->resolve($bindings, 'demo', ModuleBindingType::MENU, 'missing');
    throw new RuntimeException('missing binding must throw NOT_FOUND');
} catch (AppException $e) {
    expectSame(ErrorCode::NOT_FOUND, $e->errorCode(), 'missing binding must use stable NOT_FOUND error');
}
