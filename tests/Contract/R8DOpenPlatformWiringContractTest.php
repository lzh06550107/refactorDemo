<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$appServiceFile = $root . '/app/AppService.php';
$configFile = $root . '/config/openplatform.php';
$envFile = $root . '/.env.example';

expectTrue(is_file($configFile), 'OpenPlatform runtime config must exist');
$appService = (string) file_get_contents($appServiceFile);

$requiredBindings = [
    ['AdminSessionRepository', 'ThinkPhpAdminSessionRepository'],
    ['AdminTenantAccess', 'ThinkPhpAdminTenantAccess'],
    ['PermissionAuthorizer', 'ThinkPhpPermissionAuthorizer'],
    ['AuthorizationIntentRepository', 'ThinkPhpAuthorizationIntentRepository'],
    ['AuthorizerAccountEligibility', 'ThinkPhpAuthorizerAccountEligibility'],
    ['AuthorizerAccountBinding', 'OpenPlatformAuthorizerAccountBinding'],
    ['AuthorizerAuthorizationRepository', 'ThinkPhpAuthorizerAuthorizationRepository'],
    ['AuthorizerMetadataRepository', 'ThinkPhpAuthorizerMetadataRepository'],
    ['AuthorizerOwnershipRepository', 'ThinkPhpAuthorizerOwnershipRepository'],
    ['AuthorizerProvisioningRepository', 'ThinkPhpAuthorizerProvisioningRepository'],
    ['ProvisioningJobRepository', 'ThinkPhpProvisioningJobRepository'],
    ['ProvisioningJobScheduler', 'ThinkPhpProvisioningJobScheduler'],
    ['AuthorizerConnectionStore', 'ThinkPhpAuthorizerConnectionStore'],
    ['AuthorizerAccountStateReader', 'ThinkPhpAuthorizerAccountStateReader'],
    ['AuthorizerAccountFinalizer', 'ThinkPhpAuthorizerAccountFinalizer'],
    ['AuthorizerTenantScopeReader', 'ThinkPhpAuthorizerTenantScopeReader'],
    ['OpenPlatformHttpTransport', 'NativeOpenPlatformHttpTransport'],
    ['AuthorizerClient', 'WechatAuthorizerClient'],
    ['OpenPlatformSecretCipher', 'OpenSslOpenPlatformSecretCipher'],
    ['TransactionManager', 'ThinkPhpTransactionManager'],
];
foreach ($requiredBindings as [$contract, $implementation]) {
    expectTrue(str_contains($appService, $contract . '::class'), 'AppService explicitly maps ' . $contract);
    expectTrue(str_contains($appService, $implementation . '::class'), 'AppService maps ' . $contract . ' to ' . $implementation);
}

expectTrue(str_contains($appService, 'base64_decode'), 'secret key material is decoded from base64 configuration');
expectTrue(str_contains($appService, 'strlen($decodedKey) !== 32'), 'secret cipher requires exactly 32 decoded bytes');
expectTrue(str_contains($appService, 'AuthorizationStartService::class'), 'authorization start service has explicit callback-uri factory');
expectTrue(str_contains($appService, 'WechatAuthorizerClient::class'), 'authorizer client is built with runtime timeout configuration');
expectTrue(!str_contains($appService, 'secret_key_base64]'), 'AppService never indexes malformed secret configuration syntax');

$config = (string) file_get_contents($configFile);
foreach ([
    'authorization_callback_uri',
    'http_timeout_seconds',
    'secret_key_version',
    'secret_key_base64',
] as $key) {
    expectTrue(str_contains($config, "'{$key}'"), 'OpenPlatform config exposes ' . $key);
}

$env = (string) file_get_contents($envFile);
foreach ([
    'WEPLATFORM_OPENPLATFORM_AUTHORIZATION_CALLBACK_URI=',
    'WEPLATFORM_OPENPLATFORM_HTTP_TIMEOUT_SECONDS=',
    'WEPLATFORM_OPENPLATFORM_SECRET_KEY_VERSION=',
    'WEPLATFORM_OPENPLATFORM_SECRET_KEY_BASE64=',
] as $key) {
    expectTrue(str_contains($env, $key), '.env.example documents ' . $key);
}

$routes = (string) file_get_contents($root . '/app/api/route/app.php');
expectTrue(str_contains($routes, 'OpenPlatformAdminContextMiddleware::class'), 'R8D admin routes use admin context middleware');
