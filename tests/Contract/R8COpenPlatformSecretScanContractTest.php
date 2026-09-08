<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$migration = (string) file_get_contents($root . '/database/migrations/20260908_008_openplatform_authorizer_lifecycle_up.sql');
foreach ([
    '/`state`\s/i',
    '/`pre_auth_code`\s/i',
    '/`authorization_code`\s/i',
    '/`authorizer_refresh_token`\s/i',
    '/`authorizer_access_token`\s/i',
] as $pattern) {
    expectTrue(preg_match($pattern, $migration) !== 1, 'R8C migration must not define plaintext provider credential/code columns: ' . $pattern);
}
expectTrue(str_contains($migration, '`state_hash`') && str_contains($migration, '`pre_auth_code_hash`'), 'R8C migration persists only authorization correlation hashes');
expectTrue(str_contains($migration, '`refresh_token_ciphertext`') && str_contains($migration, '`token_ciphertext`'), 'R8C migration persists encrypted authorizer credentials');

$productionFiles = [];
foreach ([$root . '/app/openplatform', $root . '/app/miniapp/infrastructure', $root . '/app/api/controller/V1'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') { $productionFiles[] = $file->getPathname(); }
    }
}
$secretPatterns = [
    '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
    '/\bghp_[A-Za-z0-9]{30,}\b/',
    '/\bgithub_pat_[A-Za-z0-9_]{30,}\b/',
    '/\bAKIA[0-9A-Z]{16}\b/',
];
foreach ($productionFiles as $file) {
    $source = (string) file_get_contents($file);
    foreach ($secretPatterns as $pattern) {
        expectTrue(preg_match($pattern, $source) !== 1, 'possible committed secret detected in R8C production source: ' . $file);
    }
}

foreach ([
    $root . '/app/api/controller/V1/OpenPlatformEventController.php',
    $root . '/app/api/controller/V1/OpenPlatformAuthorizationStartController.php',
    $root . '/app/api/controller/V1/OpenPlatformAuthorizationCallbackController.php',
] as $file) {
    expectTrue(is_file($file), 'R8C controller required for response secret scan: ' . $file);
    $source = (string) file_get_contents($file);
    foreach (['authorizer_refresh_token', 'authorizer_access_token', 'component_access_token', 'component_verify_ticket'] as $forbidden) {
        expectTrue(!str_contains($source, $forbidden), 'R8C controller must not serialize provider credential field: ' . $forbidden);
    }
}
