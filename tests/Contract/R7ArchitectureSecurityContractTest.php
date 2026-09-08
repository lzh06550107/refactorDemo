<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$architectureRoots = [
    $root . '/app/member/domain',
    $root . '/app/member/application',
    $root . '/app/oauth/domain',
    $root . '/app/oauth/application',
    $root . '/app/webhook/domain',
    $root . '/app/webhook/application',
    $root . '/app/webhook/security',
];

$phpFiles = static function (array $roots): array {
    $files = [];
    foreach ($roots as $directory) {
        expectTrue(is_dir($directory), 'R7 architecture scan directory missing: ' . $directory);
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
    expectTrue(!str_contains($source, 'think\\facade\\'), 'domain/application/security must not import ThinkPHP Facades: ' . $file);
    expectTrue(!str_contains($source, 'think\\Db'), 'domain/application/security must not depend on ThinkPHP DB: ' . $file);
    expectTrue(!str_contains($source, '$_W'), 'R7 domain/application must not depend on legacy $_W globals: ' . $file);
    expectTrue(!str_contains($source, '$_GPC'), 'R7 domain/application must not depend on legacy $_GPC globals: ' . $file);
}

$secretRoots = [
    $root . '/app/member',
    $root . '/app/oauth',
    $root . '/app/webhook',
    $root . '/tests/Unit/Member',
    $root . '/tests/Unit/OAuth',
    $root . '/tests/Unit/Webhook',
    $root . '/tests/Component/Member',
    $root . '/tests/Component/OAuth',
    $root . '/tests/Component/Webhook',
];

$secretPatterns = [
    '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
    '/\bsk-[A-Za-z0-9_-]{20,}\b/',
    '/\bghp_[A-Za-z0-9]{30,}\b/',
    '/\bgithub_pat_[A-Za-z0-9_]{30,}\b/',
    '/\bAKIA[0-9A-Z]{16}\b/',
    '/(?:client[_-]?secret|appsecret|access[_-]?token)\s*[=:>]\s*[\'\"]?[A-Za-z0-9_\/.+\-]{16,}/i',
];

foreach ($phpFiles($secretRoots) as $file) {
    $source = (string) file_get_contents($file);
    foreach ($secretPatterns as $pattern) {
        expectTrue(preg_match($pattern, $source) !== 1, 'possible committed secret detected in R7 source/test: ' . $file);
    }
}

foreach ([
    $root . '/docs/migration/r20-member-oauth-webhook-compatibility.md',
    $root . '/docs/verification/member-oauth-webhook-r7.md',
] as $file) {
    expectTrue(is_file($file), 'R7 release document missing: ' . $file);
    $source = (string) file_get_contents($file);
    foreach ($secretPatterns as $pattern) {
        expectTrue(preg_match($pattern, $source) !== 1, 'possible committed secret detected in R7 documentation: ' . $file);
    }
}
