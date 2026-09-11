<?php

declare(strict_types=1);

namespace app\web\support;

use JsonException;
use RuntimeException;

final class WebAssetManifest
{
    /** @var array<string,mixed>|null */
    private ?array $manifest = null;

    public function __construct(
        private readonly string $manifestPath,
        private readonly bool $devMode,
        private readonly string $devOrigin,
    ) {
    }

    /** @return array{js:string,css:string} */
    public function forEntry(string $entry): array
    {
        if ($this->devMode) {
            $origin = rtrim($this->devOrigin, '/');
            if ($origin === '') {
                throw new RuntimeException('Web asset development origin is empty.');
            }

            return [
                'js' => $origin . '/' . ltrim($entry, '/'),
                'css' => $origin . '/src/css/main.css',
            ];
        }

        $manifest = $this->manifest();
        $record = $manifest[$entry] ?? null;
        if (!is_array($record)) {
            throw new RuntimeException('Web asset manifest entry is missing: ' . $entry);
        }

        $file = $record['file'] ?? null;
        $css = $record['css'] ?? null;
        if (!is_string($file) || !is_array($css) || !isset($css[0]) || !is_string($css[0])) {
            throw new RuntimeException('Web asset manifest entry is incomplete: ' . $entry);
        }

        return [
            'js' => '/build/web/' . $this->validateRelativeAssetPath($file),
            'css' => '/build/web/' . $this->validateRelativeAssetPath($css[0]),
        ];
    }

    /** @return array<string,mixed> */
    private function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $json = @file_get_contents($this->manifestPath);
        if (!is_string($json)) {
            throw new RuntimeException('Web asset manifest could not be read.');
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Web asset manifest is invalid JSON.', 0, $e);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Web asset manifest must decode to an object.');
        }

        return $this->manifest = $decoded;
    }

    private function validateRelativeAssetPath(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')) {
            throw new RuntimeException('Web asset path is invalid.');
        }
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            throw new RuntimeException('Web asset path must be relative.');
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                throw new RuntimeException('Web asset path traversal is not allowed.');
            }
        }

        return $path;
    }
}
