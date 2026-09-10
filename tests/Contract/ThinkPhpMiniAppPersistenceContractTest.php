<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$providerPath = $root . '/app/miniapp/infrastructure/ThinkPhpMiniAppProviderAccountRepository.php';
$sessionPath = $root . '/app/miniapp/infrastructure/ThinkPhpMiniAppSessionRepository.php';
$cipherPath = $root . '/app/miniapp/infrastructure/OpenSslSessionKeyCipher.php';

foreach ([$providerPath, $sessionPath, $cipherPath] as $path) {
    expectTrue(is_file($path), 'missing MiniApp persistence/security adapter: ' . $path);
}

$provider = (string) file_get_contents($providerPath);
expectTrue(str_contains($provider, "Db::table('miniapp_provider_accounts')"), 'provider repository must query miniapp_provider_accounts');
expectTrue(str_contains($provider, "'tenant_id' => \$tenantId"), 'provider lookup must include tenant id');
expectTrue(str_contains($provider, "'account_id' => \$accountId"), 'provider lookup must include account id');
expectTrue(str_contains($provider, "'enabled' => 1"), 'provider lookup must reject disabled rows');
expectTrue(str_contains($provider, 'MiniAppConnectionMode::from'), 'provider repository must restore explicit connection mode');

$session = (string) file_get_contents($sessionPath);
expectTrue(str_contains($session, "Db::table('miniapp_sessions')"), 'session repository must use miniapp_sessions');
expectTrue(str_contains($session, "'token_hash'"), 'session repository stores token hash');
expectTrue(str_contains($session, "'session_key_ciphertext'"), 'session repository stores encrypted session key');
expectTrue(str_contains($session, "'session_key_key_version'"), 'session repository stores encryption key version');
expectTrue(!str_contains($session, "'session_token'"), 'session repository must never store plaintext session token');
expectTrue(!preg_match("/'session_key'\s*=>/", $session), 'session repository must never store raw session key');

$cipher = (string) file_get_contents($cipherPath);
expectTrue(str_contains(strtolower($cipher), 'aes-256-gcm'), 'session key cipher must use AES-256-GCM');
expectTrue(str_contains($cipher, 'random_bytes(12)'), 'session key cipher must use a random 96-bit GCM IV');
expectTrue(str_contains($cipher, 'keyVersion'), 'session key cipher must preserve explicit key version');
expectTrue(str_contains($cipher, 'openssl_encrypt'), 'session key cipher must use authenticated OpenSSL encryption');
expectTrue(str_contains($cipher, 'openssl_decrypt'), 'session key cipher must authenticate before reveal');
