<?php

declare(strict_types=1);

namespace app\theme\domain;

use InvalidArgumentException;

final readonly class ThemeDefinition
{
    public function __construct(private string $id, private string $key, private string $title, private bool $enabled)
    {
        if (trim($id) === '' || trim($title) === '' || preg_match('/^[A-Za-z0-9_-]+$/', $key) !== 1) {
            throw new InvalidArgumentException('Theme definition is invalid.');
        }
    }
    public function id(): string { return $this->id; }
    public function key(): string { return $this->key; }
    public function title(): string { return $this->title; }
    public function enabled(): bool { return $this->enabled; }
}
