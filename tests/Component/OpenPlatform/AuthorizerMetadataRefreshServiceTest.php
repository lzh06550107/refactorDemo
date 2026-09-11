<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$serviceFile = $root . '/modules/openplatform/application/AuthorizerMetadataRefreshService.php';
$scopeFile = $root . '/modules/openplatform/contract/AuthorizerTenantScopeReader.php';
$adapterFile = $root . '/modules/openplatform/infrastructure/ThinkPhpAuthorizerTenantScopeReader.php';

expectTrue(is_file($serviceFile), 'metadata refresh service must exist');
expectTrue(is_file($scopeFile), 'Tenant authorizer scope reader contract must exist');
expectTrue(is_file($adapterFile), 'ThinkPHP Tenant authorizer scope reader must exist');

$service = (string) file_get_contents($serviceFile);
expectTrue(str_contains($service, 'AuthorizerTenantScopeReader'), 'metadata refresh depends on explicit Tenant scope proof');
expectTrue(str_contains($service, 'AuthorizerMetadataSyncService'), 'metadata refresh reuses trusted metadata sync service');
expectTrue(str_contains($service, 'ErrorCode::NOT_FOUND'), 'out-of-scope authorizer is hidden behind tenant-scoped 404');
expectTrue(str_contains($service, "'admin_metadata_refresh'"), 'manual refresh records a stable metadata source');
expectTrue(!str_contains($service, 'QuotaService'), 'metadata refresh never consumes quota');
expectTrue(!str_contains($service, 'AuthorizerAccountFinalizer'), 'metadata refresh never creates an Account');
expectTrue(!str_contains($service, 'AuthorizerConnectionStore'), 'metadata refresh never claims or moves provider ownership');

$scopePos = strpos($service, 'allowsTenant');
$syncPos = strpos($service, '->sync(');
expectTrue($scopePos !== false && $syncPos !== false && $scopePos < $syncPos, 'Tenant scope is proven before any provider metadata API call');

$adapter = (string) file_get_contents($adapterFile);
expectTrue(str_contains($adapter, "Db::table('authorizer_account_ownerships')"), 'scope reader accepts current canonical ownership');
expectTrue(str_contains($adapter, "Db::table('authorizer_provisionings')"), 'scope reader accepts current Tenant provisioning');
foreach (['provisioned', 'reconnected', 'quota_blocked', 'binding_conflict', 'metadata_failed', 'metadata_type_conflict', 'authorization_inactive'] as $terminal) {
    expectTrue(str_contains($adapter, "'{$terminal}'"), 'scope reader excludes terminal provisioning status ' . $terminal);
}
