<?php

declare(strict_types=1);

namespace modules\theme\infrastructure;

use JsonException;
use modules\theme\contract\ThemePackageRepository;
use modules\theme\domain\InvalidThemePackage;
use modules\theme\domain\ResolvedThemePage;
use modules\theme\domain\ThemeManifest;
use modules\theme\domain\ThemePageNotFound;

final class FilesystemThemePackageRepository implements ThemePackageRepository
{
    private string $themesRoot;

    public function __construct(string $themesRoot)
    {
        $resolvedRoot = realpath($themesRoot);
        if ($resolvedRoot === false || !is_dir($resolvedRoot)) {
            throw new InvalidThemePackage('Themes root is unavailable.');
        }
        $this->themesRoot = rtrim($resolvedRoot, DIRECTORY_SEPARATOR);
    }

    public function resolve(string $themeKey, string $pageKey): ResolvedThemePage
    {
        if (!$this->isSafeKey($themeKey) || !$this->isSafeKey($pageKey)) {
            throw new ThemePageNotFound('Theme page was not found.');
        }

        $lexicalThemeRoot = $this->themesRoot . DIRECTORY_SEPARATOR . $themeKey;
        if (!file_exists($lexicalThemeRoot) && !is_link($lexicalThemeRoot)) {
            throw new ThemePageNotFound('Theme page was not found.');
        }

        $themeRoot = realpath($lexicalThemeRoot);
        if ($themeRoot === false || !is_dir($themeRoot)) {
            throw new InvalidThemePackage('Theme package directory is invalid.');
        }
        if (dirname($themeRoot) !== $this->themesRoot) {
            throw new InvalidThemePackage('Theme package escapes the configured themes root.');
        }

        $manifest = $this->loadManifest($themeRoot, $themeKey);
        $pagePath = $manifest->pagePath($pageKey);
        if ($pagePath === null) {
            throw new ThemePageNotFound('Theme page was not found.');
        }

        $headerPath = $manifest->componentPath('header');
        $footerPath = $manifest->componentPath('footer');
        if ($headerPath === null || $footerPath === null) {
            throw new InvalidThemePackage('Theme package is missing required components.');
        }

        return new ResolvedThemePage(
            $manifest,
            $this->readDeclaredTemplate($themeRoot, $manifest->layoutPath()),
            $this->readDeclaredTemplate($themeRoot, $pagePath),
            $this->readDeclaredTemplate($themeRoot, $headerPath),
            $this->readDeclaredTemplate($themeRoot, $footerPath),
        );
    }

    private function loadManifest(string $themeRoot, string $expectedThemeKey): ThemeManifest
    {
        $manifestPath = $this->resolveContainedFile($themeRoot, 'theme.json');
        $json = file_get_contents($manifestPath);
        if (!is_string($json)) {
            throw new InvalidThemePackage('Theme manifest cannot be read.');
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidThemePackage('Theme manifest contains invalid JSON.', 0, $error);
        }

        if (!is_array($data)) {
            throw new InvalidThemePackage('Theme manifest must decode to an object.');
        }

        return ThemeManifest::fromArray($expectedThemeKey, $data);
    }

    private function readDeclaredTemplate(string $themeRoot, string $relativePath): string
    {
        $path = $this->resolveContainedFile($themeRoot, $relativePath);
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new InvalidThemePackage('Declared theme template cannot be read.');
        }
        return $contents;
    }

    private function resolveContainedFile(string $themeRoot, string $relativePath): string
    {
        $lexicalPath = $themeRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $resolvedPath = realpath($lexicalPath);
        if ($resolvedPath === false || !is_file($resolvedPath)) {
            throw new InvalidThemePackage('Declared theme file is missing.');
        }

        $prefix = rtrim($themeRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($resolvedPath, $prefix)) {
            throw new InvalidThemePackage('Declared theme file escapes the theme package.');
        }

        return $resolvedPath;
    }

    private function isSafeKey(string $key): bool
    {
        return preg_match('/^[A-Za-z0-9_-]+$/', $key) === 1;
    }
}
