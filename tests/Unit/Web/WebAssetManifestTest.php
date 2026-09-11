<?php

declare(strict_types=1);

use app\web\support\WebAssetManifest;

$root = sys_get_temp_dir() . '/weplatform-web-assets-' . bin2hex(random_bytes(6));
if (!mkdir($root, 0777, true) && !is_dir($root)) {
    throw new RuntimeException('could not create web asset test directory');
}

$manifestPath = $root . '/manifest.json';
$writeManifest = static function (array $manifest) use ($manifestPath): void {
    $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || file_put_contents($manifestPath, $encoded) === false) {
        throw new RuntimeException('could not write asset manifest fixture');
    }
};

$validEntry = [
    'src/js/main.js' => [
        'file' => 'assets/main-abc123.js',
        'src' => 'src/js/main.js',
        'isEntry' => true,
        'css' => ['assets/main-def456.css'],
    ],
];

$writeManifest($validEntry);
$assets = (new WebAssetManifest($manifestPath, false, 'http://127.0.0.1:5174'))->forEntry('src/js/main.js');
expectSame('/build/web/assets/main-abc123.js', $assets['js'], 'production JS asset is resolved from Vite manifest');
expectSame('/build/web/assets/main-def456.css', $assets['css'], 'production CSS asset is resolved from Vite manifest');

expectThrows(
    fn () => (new WebAssetManifest($manifestPath, false, 'http://127.0.0.1:5174'))->forEntry('src/js/missing.js'),
    RuntimeException::class,
    'missing manifest entry must fail closed',
);

$invalidCases = [
    'missing file' => [
        'src/js/main.js' => ['css' => ['assets/main.css']],
    ],
    'missing css' => [
        'src/js/main.js' => ['file' => 'assets/main.js'],
    ],
    'absolute JS path' => [
        'src/js/main.js' => ['file' => '/assets/main.js', 'css' => ['assets/main.css']],
    ],
    'JS traversal' => [
        'src/js/main.js' => ['file' => '../main.js', 'css' => ['assets/main.css']],
    ],
    'absolute CSS path' => [
        'src/js/main.js' => ['file' => 'assets/main.js', 'css' => ['/assets/main.css']],
    ],
    'CSS traversal' => [
        'src/js/main.js' => ['file' => 'assets/main.js', 'css' => ['assets/../main.css']],
    ],
];

foreach ($invalidCases as $label => $manifest) {
    $writeManifest($manifest);
    expectThrows(
        fn () => (new WebAssetManifest($manifestPath, false, 'http://127.0.0.1:5174'))->forEntry('src/js/main.js'),
        RuntimeException::class,
        $label . ' must fail closed',
    );
}

@unlink($manifestPath);
$devAssets = (new WebAssetManifest($manifestPath, true, 'http://127.0.0.1:5174/'))->forEntry('src/js/main.js');
expectSame('http://127.0.0.1:5174/src/js/main.js', $devAssets['js'], 'development JS bypasses manifest');
expectSame('http://127.0.0.1:5174/src/css/main.css', $devAssets['css'], 'development CSS bypasses manifest');

@rmdir($root);
