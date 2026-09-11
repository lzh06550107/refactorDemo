<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$paths = [
    $root . '/modules/openplatform/application/OpenPlatformAudit.php',
    $root . '/modules/openplatform/infrastructure/AuditedAuthorizerProvisioningRepository.php',
    $root . '/modules/openplatform/infrastructure/AuditedAuthorizerMetadataRepository.php',
    $root . '/modules/openplatform/infrastructure/AuditedAuthorizerConnectionStore.php',
    $root . '/modules/openplatform/infrastructure/AuditedAuthorizerAccountFinalizer.php',
    $root . '/app/api/controller/V1/OpenPlatformAuthorizationStartController.php',
    $root . '/app/api/controller/V1/OpenPlatformAuthorizerMetadataController.php',
    $root . '/app/api/controller/V1/OpenPlatformProvisioningController.php',
];

$source = '';
foreach ($paths as $path) {
    expectTrue(is_file($path), 'Task 13 security-scanned production file exists: ' . basename($path));
    $source .= "\n" . (string) file_get_contents($path);
}

foreach ([
    'SECRET_STATE_123',
    'SECRET_PREAUTH_123',
    'SECRET_AUTH_CODE_123',
    'SECRET_REFRESH_TOKEN_123',
    'SECRET_ACCESS_TOKEN_123',
] as $sentinel) {
    expectTrue(!str_contains($source, $sentinel), 'production code never embeds test secret sentinel: ' . $sentinel);
}

$auditSource = (string) file_get_contents($root . '/modules/openplatform/application/OpenPlatformAudit.php');
foreach ([
    "'state' => true",
    "'pre_auth_code' => true",
    "'authorization_code' => true",
    "'refresh_token' => true",
    "'access_token' => true",
    "'raw_payload' => true",
    "'raw_xml' => true",
    "'ciphertext' => true",
] as $forbiddenAllowlistEntry) {
    expectTrue(!str_contains($auditSource, $forbiddenAllowlistEntry), 'audit metadata allowlist excludes secret/raw provider field ' . $forbiddenAllowlistEntry);
}
expectTrue(str_contains($auditSource, 'SAFE_METADATA_KEYS'), 'audit metadata is centrally allowlisted');
expectTrue(!str_contains($auditSource, "str_contains(\$value, 'SECRET_')"), 'production secret isolation is structural, not a test-sentinel special case');

foreach ([
    'refreshToken()',
    'accessToken()',
    'authorizationCode',
    'preAuthCode',
    'normalizedMetadataJson()',
] as $sensitiveAccessor) {
    expectTrue(!str_contains($source, $sensitiveAccessor), 'Task 13 audit emitters never read secret/raw provider accessor: ' . $sensitiveAccessor);
}
