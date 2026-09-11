<?php

declare(strict_types=1);

namespace modules\module\domain;

use InvalidArgumentException;

final readonly class ModuleCustomPermission
{
    public function __construct(
        private string $title,
        private string $permission,
        private ?string $parent = null,
    ) {
        if (trim($title) === '' || trim($permission) === '') {
            throw new InvalidArgumentException('Custom module permission title and key must not be empty.');
        }
    }

    public function title(): string { return $this->title; }
    public function permission(): string { return $this->permission; }
    public function parent(): ?string { return $this->parent; }
}
