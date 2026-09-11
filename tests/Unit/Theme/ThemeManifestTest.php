<?php

declare(strict_types=1);

use modules\theme\domain\InvalidThemePackage;
use modules\theme\domain\ThemeManifest;

$validManifest = static fn (): array => [
    'key' => 'corporate',
    'name' => 'Corporate',
    'version' => '1.0.0',
    'layout' => 'layouts/default.html',
    'pages' => [
        'index' => 'pages/index.html',
    ],
    'components' => [
        'header' => 'components/header.html',
        'footer' => 'components/footer.html',
    ],
];

$manifest = ThemeManifest::fromArray('corporate', $validManifest());
expectSame('corporate', $manifest->key(), 'manifest key is preserved');
expectSame('Corporate', $manifest->name(), 'manifest name is preserved');
expectSame('1.0.0', $manifest->version(), 'manifest version is preserved');
expectSame('layouts/default.html', $manifest->layoutPath(), 'manifest layout path is preserved');
expectSame('pages/index.html', $manifest->pagePath('index'), 'manifest page path is resolved');
expectSame('components/header.html', $manifest->componentPath('header'), 'manifest header component path is resolved');
expectSame('components/footer.html', $manifest->componentPath('footer'), 'manifest footer component path is resolved');
expectSame(null, $manifest->pagePath('missing'), 'unknown page has no declared path');
expectSame(null, $manifest->componentPath('missing'), 'unknown component has no declared path');

$expectInvalid = static function (callable $mutate, string $message) use ($validManifest): void {
    $data = $validManifest();
    $mutate($data);
    expectThrows(
        static fn () => ThemeManifest::fromArray('corporate', $data),
        InvalidThemePackage::class,
        $message,
    );
};

expectThrows(
    static fn () => ThemeManifest::fromArray('../corporate', $validManifest()),
    InvalidThemePackage::class,
    'malformed expected theme key must be rejected',
);

$expectInvalid(static function (array &$data): void { $data['key'] = 'other'; }, 'manifest key mismatch must be rejected');
$expectInvalid(static function (array &$data): void { unset($data['name']); }, 'missing name must be rejected');
$expectInvalid(static function (array &$data): void { unset($data['version']); }, 'missing version must be rejected');
$expectInvalid(static function (array &$data): void { unset($data['layout']); }, 'missing layout must be rejected');
$expectInvalid(static function (array &$data): void { unset($data['pages']); }, 'missing pages must be rejected');
$expectInvalid(static function (array &$data): void { unset($data['components']); }, 'missing components must be rejected');
$expectInvalid(static function (array &$data): void { unset($data['pages']['index']); }, 'missing index page must be rejected');
$expectInvalid(static function (array &$data): void { unset($data['components']['header']); }, 'missing header component must be rejected');
$expectInvalid(static function (array &$data): void { unset($data['components']['footer']); }, 'missing footer component must be rejected');

foreach ([
    '/absolute/layout.html',
    '../outside/layout.html',
    'layouts/../outside.html',
    "layouts/default\0.html",
] as $unsafePath) {
    $expectInvalid(
        static function (array &$data) use ($unsafePath): void { $data['layout'] = $unsafePath; },
        'unsafe template path must be rejected: ' . str_replace("\0", '<NUL>', $unsafePath),
    );
}

$expectInvalid(static function (array &$data): void { $data['layout'] = ['layouts/default.html']; }, 'non-string layout path must be rejected');
$expectInvalid(static function (array &$data): void { $data['pages']['index'] = 123; }, 'non-string page path must be rejected');
$expectInvalid(static function (array &$data): void { $data['components']['header'] = false; }, 'non-string component path must be rejected');
$expectInvalid(static function (array &$data): void { $data['pages']['../index'] = 'pages/index.html'; unset($data['pages']['index']); }, 'invalid page key must be rejected');
$expectInvalid(static function (array &$data): void { $data['components']['../header'] = 'components/header.html'; unset($data['components']['header']); }, 'invalid component key must be rejected');
