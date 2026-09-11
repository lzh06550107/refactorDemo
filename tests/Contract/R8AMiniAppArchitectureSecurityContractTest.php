<?php

declare(strict_types=1);

use app\common\error\AppException;
use modules\miniapp\infrastructure\OpenSslSessionKeyCipher;

$root = dirname(__DIR__, 2);
$architectureRoots = [
    $root . '/modules/miniapp/domain',
    $root . '/modules/miniapp/application',
];

$phpFiles = static function (array $roots): array {
    $files = [];
    foreach ($roots as $directory) {
        expectTrue(is_dir($directory), 'R8A architecture scan directory missing: ' . $directory);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }
    sort($files);
    return $files;
};

foreach ($phpFiles($architectureRoots) as $file) {
    $source = (string) file_get_contents($file);
    expectTrue(!str_contains($source, 'think\\facade\\'), 'MiniApp domain/application must not import ThinkPHP Facades: ' . $file);
    expectTrue(!str_contains($source, 'Db::'), 'MiniApp domain/application must not depend on ThinkPHP Db: ' . $file);
    expectTrue(!str_contains($source, '$_W'), 'MiniApp domain/application must not depend on legacy $_W: ' . $file);
    expectTrue(!str_contains($source, '$_GPC'), 'MiniApp domain/application must not depend on legacy $_GPC: ' . $file);
    expectTrue(!str_contains($source, 'pdo_'), 'MiniApp domain/application must not depend on legacy pdo helpers: ' . $file);
}

$sessionService = (string) file_get_contents($root . '/modules/miniapp/application/MiniAppSessionService.php');
expectTrue(!str_contains(strtolower($sessionService), 'openid'), 'MiniApp session authentication must not accept client openid evidence');

$migration = (string) file_get_contents($root . '/database/migrations/20260908_006_miniapp_identity_session_up.sql');
expectTrue(str_contains($migration, '`token_hash` char(64)'), 'MiniApp schema stores SHA-256 token hashes');
expectTrue(str_contains($migration, '`session_key_ciphertext`'), 'MiniApp schema stores protected session key ciphertext');
expectTrue(str_contains($migration, '`session_key_key_version`'), 'MiniApp schema stores session-key key version');
expectTrue(!preg_match('/`session_token`/', $migration), 'MiniApp schema must not contain raw session token column');
expectTrue(!preg_match('/`session_key`\s/', $migration), 'MiniApp schema must not contain raw session key column');

foreach ([
    $root . '/docs/migration/r20-miniapp-identity-session-compatibility.md',
    $root . '/docs/verification/miniapp-identity-session-r8a.md',
] as $file) {
    expectTrue(is_file($file), 'R8A release document missing: ' . $file);
}

$keys = ['k1' => random_bytes(32), 'k0' => random_bytes(32)];
$cipher = new OpenSslSessionKeyCipher($keys, 'k1');
$first = $cipher->protect('provider-session-key');
$second = $cipher->protect('provider-session-key');
expectSame('k1', $first->keyVersion(), 'cipher records active key version');
expectTrue($first->ciphertext() !== $second->ciphertext(), 'GCM protection uses randomized IVs');
expectSame('provider-session-key', $cipher->reveal($first), 'GCM ciphertext round-trips only through configured key');

$tampered = base64_decode($first->ciphertext(), true);
expectTrue(is_string($tampered) && strlen($tampered) > 30, 'ciphertext fixture is valid base64 payload');
$tampered[30] = chr(ord($tampered[30]) ^ 1);
try {
    $cipher->reveal(new modules\miniapp\domain\ProtectedSessionKey(base64_encode($tampered), 'k1'));
    throw new RuntimeException('tampered GCM ciphertext must fail authentication');
} catch (AppException) {
}

$secretPatterns = [
    '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
    '/\bsk-[A-Za-z0-9_-]{20,}\b/',
    '/\bghp_[A-Za-z0-9]{30,}\b/',
    '/\bgithub_pat_[A-Za-z0-9_]{30,}\b/',
    '/\bAKIA[0-9A-Z]{16}\b/',
];
foreach (array_merge($phpFiles([$root . '/modules/miniapp']), [$root . '/database/migrations/20260908_006_miniapp_identity_session_up.sql']) as $file) {
    $source = (string) file_get_contents($file);
    foreach ($secretPatterns as $pattern) {
        expectTrue(preg_match($pattern, $source) !== 1, 'possible committed secret detected in R8A source: ' . $file);
    }
}
