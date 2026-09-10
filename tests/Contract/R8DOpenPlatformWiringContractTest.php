<?php

declare(strict_types=1);

use app\openplatform\infrastructure\ConfiguredComponentCredentialProvider;

$root = dirname(__DIR__, 2);
$appServiceFile = $root . '/app/AppService.php';
$serviceRegistryFile = $root . '/app/service.php';
$configFile = $root . '/config/openplatform.php';
$platformConfigFile = $root . '/config/weplatform.php';
$envFile = $root . '/.env.example';

expectTrue(is_file($configFile), 'OpenPlatform runtime config must exist');
expectTrue(is_file($serviceRegistryFile), 'ThinkPHP application service registry must exist');
$appService = (string) file_get_contents($appServiceFile);

$requiredBindings = [
    ['AuditLogger', 'StructuredAuditLogger'],
    ['AdminSessionRepository', 'ThinkPhpAdminSessionRepository'],
    ['AdminTenantAccess', 'ThinkPhpAdminTenantAccess'],
    ['PermissionAuthorizer', 'ThinkPhpPermissionAuthorizer'],
    ['AuthorizationIntentRepository', 'ThinkPhpAuthorizationIntentRepository'],
    ['AuthorizerAccountEligibility', 'ThinkPhpAuthorizerAccountEligibility'],
    ['AuthorizerAccountBinding', 'OpenPlatformAuthorizerAccountBinding'],
    ['AuthorizerAuthorizationRepository', 'ThinkPhpAuthorizerAuthorizationRepository'],
    ['AuthorizerAuthorizationCredentialRepository', 'ThinkPhpAuthorizerAuthorizationRepository'],
    ['AuthorizerMetadataRepository', 'ThinkPhpAuthorizerMetadataRepository'],
    ['AuthorizerOwnershipRepository', 'ThinkPhpAuthorizerOwnershipRepository'],
    ['AuthorizerProvisioningRepository', 'ThinkPhpAuthorizerProvisioningRepository'],
    ['ProvisioningJobRepository', 'ThinkPhpProvisioningJobRepository'],
    ['ProvisioningJobScheduler', 'ThinkPhpProvisioningJobScheduler'],
    ['AuthorizerConnectionStore', 'ThinkPhpAuthorizerConnectionStore'],
    ['AuthorizerAccountStateReader', 'ThinkPhpAuthorizerAccountStateReader'],
    ['AuthorizerAccountFinalizer', 'ThinkPhpAuthorizerAccountFinalizer'],
    ['AuthorizerTenantScopeReader', 'ThinkPhpAuthorizerTenantScopeReader'],
    ['AuthorizerTokenRepository', 'ThinkPhpAuthorizerTokenRepository'],
    ['AuthorizerRefreshLeaseRepository', 'ThinkPhpAuthorizerRefreshLeaseRepository'],
    ['ComponentPlatformRepository', 'ThinkPhpComponentPlatformRepository'],
    ['ComponentTicketRepository', 'ThinkPhpComponentTicketRepository'],
    ['ComponentTokenRepository', 'ThinkPhpComponentTokenRepository'],
    ['ComponentRefreshLeaseRepository', 'ThinkPhpComponentRefreshLeaseRepository'],
    ['ComponentEventInboxRepository', 'ThinkPhpComponentEventInboxRepository'],
    ['ComponentCredentialProvider', 'ConfiguredComponentCredentialProvider'],
    ['ComponentTokenClient', 'WechatComponentTokenClient'],
    ['OpenPlatformHttpTransport', 'NativeOpenPlatformHttpTransport'],
    ['AuthorizerClient', 'WechatAuthorizerClient'],
    ['OpenPlatformSecretCipher', 'OpenSslOpenPlatformSecretCipher'],
    ['TransactionManager', 'ThinkPhpTransactionManager'],
];
foreach ($requiredBindings as [$contract, $implementation]) {
    expectTrue(str_contains($appService, $contract . '::class'), 'AppService explicitly maps ' . $contract);
    expectTrue(str_contains($appService, $implementation . '::class'), 'AppService maps ' . $contract . ' to ' . $implementation);
}

expectTrue(str_contains($appService, 'base64_decode($encoded, true)'), 'secret key material uses strict base64 decoding');
expectTrue(str_contains($appService, 'strlen($decodedKey) !== 32'), 'secret cipher requires exactly 32 decoded bytes');
expectTrue(str_contains($appService, 'AuthorizationStartService::class'), 'authorization start service has explicit callback-uri factory');
expectTrue(str_contains($appService, 'WechatAuthorizerClient::class'), 'authorizer client is built with runtime timeout configuration');
expectTrue(str_contains($appService, 'WechatComponentTokenClient::class'), 'component token client is built with runtime timeout configuration');
expectTrue(str_contains($appService, 'SessionTokenHasher::class'), 'admin session hasher has explicit secret factory');
expectTrue(str_contains($appService, 'weplatform.admin_session_pepper'), 'admin session pepper uses dedicated platform config');
expectTrue(str_contains($appService, 'credentialSecretMap'), 'component credentials resolve through configured secret references');
expectTrue(str_contains($appService, 'AuthorizationEventService::class'), 'provider authorization event projection is explicitly constructed');
expectTrue(str_contains($appService, 'AuthorizerConnectionService::class'), 'provider connection projection is not left at nullable default');
expectTrue(str_contains($appService, 'AuthorizerMetadataSyncService::class'), 'provider metadata projection is not left at nullable default');
expectTrue(str_contains($appService, 'OpenPlatformEventService::class'), 'provider ingress explicitly injects authorization event projection');
expectTrue(str_contains($appService, 'ComponentTicketService::class'), 'union-typed ComponentTicketService has explicit factory');
expectTrue(!str_contains($appService, 'Fake'), 'production container wiring must not reference Fake implementations');
expectTrue(!str_contains(strtolower($appService), 'authkey'), 'admin session pepper must not reuse legacy authkey');
expectTrue(!str_contains($appService, 'secret_key_base64]'), 'AppService never indexes malformed secret configuration syntax');

$serviceRegistry = (string) file_get_contents($serviceRegistryFile);
expectTrue(str_contains($serviceRegistry, 'AppService::class'), 'ThinkPHP service registry loads AppService');

$config = (string) file_get_contents($configFile);
foreach ([
    'authorization_callback_uri',
    'http_timeout_seconds',
    'secret_key_version',
    'secret_key_base64',
    'credential_secrets_json',
] as $key) {
    expectTrue(str_contains($config, "'{$key}'"), 'OpenPlatform config exposes ' . $key);
}
$platformConfig = (string) file_get_contents($platformConfigFile);
expectTrue(str_contains($platformConfig, "'admin_session_pepper'"), 'platform config exposes dedicated admin session pepper');

$env = (string) file_get_contents($envFile);
foreach ([
    'WEPLATFORM_ADMIN_SESSION_PEPPER=',
    'WEPLATFORM_OPENPLATFORM_AUTHORIZATION_CALLBACK_URI=',
    'WEPLATFORM_OPENPLATFORM_HTTP_TIMEOUT_SECONDS=',
    'WEPLATFORM_OPENPLATFORM_SECRET_KEY_VERSION=',
    'WEPLATFORM_OPENPLATFORM_SECRET_KEY_BASE64=',
    'WEPLATFORM_OPENPLATFORM_CREDENTIAL_SECRETS_JSON=',
] as $key) {
    expectTrue(str_contains($env, $key), '.env.example documents ' . $key);
}

$credentials = new ConfiguredComponentCredentialProvider([
    'secret/app' => 'app-secret',
    'secret/verify' => 'verify-token',
]);
expectSame('app-secret', $credentials->secretFor('secret/app'), 'configured credential provider resolves known ref');
expectSame('verify-token', $credentials->secretFor('secret/verify'), 'configured credential refs remain isolated');
expectThrows(
    static fn() => $credentials->secretFor('secret/missing'),
    RuntimeException::class,
    'unknown credential ref fails closed',
);
expectThrows(
    static fn() => new ConfiguredComponentCredentialProvider(['secret/app' => '']),
    RuntimeException::class,
    'empty configured credential fails closed',
);

$routes = (string) file_get_contents($root . '/app/api/route/app.php');
$adminRoutes = [
    "Route::post('v1/openplatform/components/:componentPlatformId/authorization-intents'",
    "Route::get('v1/openplatform/provisionings/:id'",
    "Route::post('v1/openplatform/provisionings/:id/retry'",
    "Route::post('v1/openplatform/components/:componentPlatformId/authorizers/:authorizerAppId/metadata/refresh'",
];
foreach ($adminRoutes as $needle) {
    $start = strpos($routes, $needle);
    expectTrue($start !== false, 'admin route exists: ' . $needle);
    $end = strpos($routes, ';', $start);
    expectTrue($end !== false, 'admin route statement terminates: ' . $needle);
    expectTrue(
        str_contains(substr($routes, $start, $end - $start), 'OpenPlatformAdminContextMiddleware::class'),
        'admin route uses admin context middleware: ' . $needle,
    );
}

$providerRoutes = [
    "Route::post('v1/openplatform/components/:componentPlatformId/events'",
    "Route::post('v1/openplatform/components/:componentPlatformId/ticket'",
    "Route::get('v1/openplatform/authorization/callback'",
];
foreach ($providerRoutes as $needle) {
    $start = strpos($routes, $needle);
    expectTrue($start !== false, 'provider route exists: ' . $needle);
    $end = strpos($routes, ';', $start);
    expectTrue($end !== false, 'provider route statement terminates: ' . $needle);
    expectTrue(
        !str_contains(substr($routes, $start, $end - $start), 'OpenPlatformAdminContextMiddleware::class'),
        'provider ingress must not use admin context middleware: ' . $needle,
    );
}


$ci = (string) file_get_contents($root . '/.github/workflows/ci.yml');
expectTrue(str_contains($ci, '/api/v1/openplatform/provisionings/runtime-smoke'), 'CI smoke resolves unauthenticated R8D admin provisioning route');
expectTrue(str_contains($ci, '/api/v1/openplatform/components/runtime-smoke/events'), 'CI smoke resolves provider event ingress without admin middleware');
expectTrue(str_contains($ci, '/api/v1/openplatform/components/runtime-smoke/ticket'), 'CI smoke resolves provider ticket ingress without admin middleware');
expectTrue(str_contains($ci, 'WEPLATFORM_ADMIN_SESSION_PEPPER=ci-admin-session-pepper'), 'CI runtime provides dedicated non-production admin pepper fixture');
expectTrue(str_contains($ci, 'WEPLATFORM_OPENPLATFORM_SECRET_KEY_BASE64='), 'CI runtime provides OpenPlatform cipher fixture without repository plaintext secret defaults');
