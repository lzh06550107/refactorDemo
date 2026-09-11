<?php

declare(strict_types=1);

namespace modules\theme\domain;

use InvalidArgumentException;

final readonly class ThemeVersion
{
    public function __construct(
        private string $id,
        private string $themeId,
        private string $version,
        private string $templateRoot,
        private string $manifestHash,
    ) {
        foreach (['id' => $id, 'themeId' => $themeId, 'version' => $version, 'templateRoot' => $templateRoot] as $key => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($key . ' must not be empty.');
            }
        }
        if (str_starts_with($templateRoot, '/') || str_contains($templateRoot, '\\') || preg_match('~(^|/)\.\.(/|$)~', $templateRoot) === 1) {
            throw new InvalidArgumentException('Theme template root must be a safe relative path.');
        }
        if (preg_match('/^[a-f0-9]{64}$/i', $manifestHash) !== 1) {
            throw new InvalidArgumentException('Manifest hash must be a SHA-256 hex string.');
        }
    }
    public function id(): string { return $this->id; }
    public function themeId(): string { return $this->themeId; }
    public function version(): string { return $this->version; }
    public function templateRoot(): string { return $this->templateRoot; }
    public function manifestHash(): string { return strtolower($this->manifestHash); }
}
