<?php

declare(strict_types=1);

namespace app\theme\domain;

use InvalidArgumentException;

final readonly class StyleInstance
{
    /** @param array<string,string> $values */
    public function __construct(
        private string $id,
        private string $tenantId,
        private string $themeVersionId,
        private string $name,
        private int $revision,
        private array $values,
    ) {
        foreach (['id' => $id, 'tenantId' => $tenantId, 'themeVersionId' => $themeVersionId, 'name' => $name] as $key => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($key . ' must not be empty.');
            }
        }
        if ($revision <= 0) {
            throw new InvalidArgumentException('Style revision must be positive.');
        }
        foreach ($values as $key => $value) {
            if (!is_string($key) || preg_match('/^[A-Za-z0-9_-]+$/', $key) !== 1 || !is_string($value)) {
                throw new InvalidArgumentException('Style values must be string pairs with safe variable names.');
            }
        }
    }
    public function id(): string { return $this->id; }
    public function tenantId(): string { return $this->tenantId; }
    public function themeVersionId(): string { return $this->themeVersionId; }
    public function name(): string { return $this->name; }
    public function revision(): int { return $this->revision; }
    /** @return array<string,string> */ public function values(): array { return $this->values; }

    /** @param array<string,string> $values */
    public function withValues(array $values): self
    {
        return new self($this->id, $this->tenantId, $this->themeVersionId, $this->name, $this->revision + 1, $values);
    }
}
