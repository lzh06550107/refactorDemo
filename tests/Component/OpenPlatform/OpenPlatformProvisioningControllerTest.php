<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$provisioningController = $root . '/app/api/controller/V1/OpenPlatformProvisioningController.php';
$metadataController = $root . '/app/api/controller/V1/OpenPlatformAuthorizerMetadataController.php';
$routeFile = $root . '/app/api/route/app.php';

expectTrue(is_file($provisioningController), 'provisioning admin controller must exist');
expectTrue(is_file($metadataController), 'authorizer metadata admin controller must exist');

$provisioning = (string) file_get_contents($provisioningController);
expectTrue(str_contains($provisioning, 'OpenPlatformAdminGuard'), 'provisioning controller uses IAM guard');
expectTrue(str_contains($provisioning, 'OpenPlatformPermission::READ'), 'query requires read permission');
expectTrue(str_contains($provisioning, 'OpenPlatformPermission::RETRY_PROVISION'), 'retry requires retry permission');
expectTrue(str_contains($provisioning, 'AuthorizerProvisioningQueryService'), 'query delegates to tenant-scoped query service');
expectTrue(str_contains($provisioning, 'AuthorizerProvisioningRetryService'), 'retry delegates to stage-aware retry service');
expectTrue(str_contains($provisioning, '$this->context->tenantId()'), 'Tenant comes only from trusted RequestContext');
expectTrue(str_contains($provisioning, '->code(202)'), 'accepted retry returns HTTP 202');
expectTrue(str_contains($provisioning, "'status' => \$result->status()->value"), 'query serializes business status without converting it into HTTP failure');

$metadata = (string) file_get_contents($metadataController);
expectTrue(str_contains($metadata, 'OpenPlatformPermission::REFRESH_METADATA'), 'metadata refresh requires dedicated permission');
expectTrue(str_contains($metadata, 'AuthorizerMetadataRefreshService'), 'metadata controller delegates to scoped refresh service');
expectTrue(str_contains($metadata, '$this->context->tenantId()'), 'metadata refresh Tenant comes from trusted RequestContext');

$routes = (string) file_get_contents($routeFile);
foreach ([
    "Route::get('v1/openplatform/provisionings/:id'",
    "Route::post('v1/openplatform/provisionings/:id/retry'",
    "Route::post('v1/openplatform/components/:componentPlatformId/authorizers/:authorizerAppId/metadata/refresh'",
] as $route) {
    expectTrue(str_contains($routes, $route), 'R8D admin route is registered: ' . $route);
}
expectTrue(substr_count($routes, 'OpenPlatformAdminContextMiddleware::class') === 4, 'admin middleware attaches only to authorization-start plus three R8D admin routes');

foreach ([
    "components/:componentPlatformId/events', 'V1.OpenPlatformEventController/receive'",
    "components/:componentPlatformId/ticket', 'V1.OpenPlatformTicketController/receive'",
    "authorization/callback', 'V1.OpenPlatformAuthorizationCallbackController/receive'",
] as $providerRoute) {
    $pos = strpos($routes, $providerRoute);
    expectTrue($pos !== false, 'provider ingress route remains registered');
    $lineEnd = strpos($routes, "\n", $pos);
    $line = substr($routes, $pos, ($lineEnd === false ? strlen($routes) : $lineEnd) - $pos);
    expectTrue(!str_contains($line, 'OpenPlatformAdminContextMiddleware'), 'provider ingress is never converted to admin IAM route');
}
