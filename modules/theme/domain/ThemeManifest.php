<?php

declare(strict_types=1);

namespace modules\theme\domain;

final readonly class ThemeManifest
{
    /** @param array<string,string> $pages @param array<string,string> $components */
    private function __construct(
        private string $key,
        private string $name,
        private string $version,
        private string $layoutPath,
        private array $pages,
        private array $components,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(string $expectedThemeKey, array $data): self
    {
        self::assertKey($expectedThemeKey, 'expected theme key');

        foreach (['key', 'name', 'version', 'layout', 'pages', 'components'] as $required) {
            if (!array_key_exists($required, $data)) {
                throw new InvalidThemePackage('Theme manifest is missing required field: ' . $required);
            }
        }

        $key = $data['key'];
        if (!is_string($key)) {
            throw new InvalidThemePackage('Theme manifest key must be a string.');
        }
        self::assertKey($key, 'manifest theme key');
        if ($key !== $expectedThemeKey) {
            throw new InvalidThemePackage('Theme manifest key does not match the resolved theme.');
        }

        $name = self::nonEmptyString($data['name'], 'Theme manifest name');
        $version = self::nonEmptyString($data['version'], 'Theme manifest version');
        $layout = self::normalizeTemplatePath($data['layout'], 'Theme manifest layout');

        if (!is_array($data['pages'])) {
            throw new InvalidThemePackage('Theme manifest pages must be an object.');
        }
        if (!is_array($data['components'])) {
            throw new InvalidThemePackage('Theme manifest components must be an object.');
        }

        $pages = self::normalizePathMap($data['pages'], 'page');
        $components = self::normalizePathMap($data['components'], 'component');

        if (!isset($pages['index'])) {
            throw new InvalidThemePackage('Theme manifest must declare the index page.');
        }
        if (!isset($components['header'])) {
            throw new InvalidThemePackage('Theme manifest must declare the header component.');
        }
        if (!isset($components['footer'])) {
            throw new InvalidThemePackage('Theme manifest must declare the footer component.');
        }

        return new self($key, $name, $version, $layout, $pages, $components);
    }

    public function key(): string { return $this->key; }
    public function name(): string { return $this->name; }
    public function version(): string { return $this->version; }
    public function layoutPath(): string { return $this->layoutPath; }
    public function pagePath(string $pageKey): ?string { return $this->pages[$pageKey] ?? null; }
    public function componentPath(string $componentKey): ?string { return $this->components[$componentKey] ?? null; }

    private static function assertKey(string $key, string $label): void
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $key) !== 1) {
            throw new InvalidThemePackage(ucfirst($label) . ' is invalid.');
        }
    }

    private static function nonEmptyString(mixed $value, string $label): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidThemePackage($label . ' must be a non-empty string.');
        }
        return $value;
    }

    /** @param array<mixed,mixed> $values @return array<string,string> */
    private static function normalizePathMap(array $values, string $kind): array
    {
        $normalized = [];
        foreach ($values as $key => $path) {
            if (!is_string($key)) {
                throw new InvalidThemePackage('Theme ' . $kind . ' key must be a string.');
            }
            self::assertKey($key, 'theme ' . $kind . ' key');
            $normalized[$key] = self::normalizeTemplatePath($path, 'Theme ' . $kind . ' path');
        }
        return $normalized;
    }

    private static function normalizeTemplatePath(mixed $value, string $label): string
    {
        if (!is_string($value) || $value === '' || str_contains($value, "\0")) {
            throw new InvalidThemePackage($label . ' must be a safe relative string path.');
        }

        $normalized = str_replace('\\', '/', $value);
        if (str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:\//', $normalized) === 1) {
            throw new InvalidThemePackage($label . ' must be relative.');
        }

        $segments = explode('/', $normalized);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidThemePackage($label . ' contains an unsafe path segment.');
            }
        }

        return implode('/', $segments);
    }
}
