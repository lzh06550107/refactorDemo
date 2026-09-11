<?php

declare(strict_types=1);

namespace modules\module\domain;

use InvalidArgumentException;

final readonly class ModulePermission
{
    /** @param list<ModulePermission> $subPermissions */
    public function __construct(
        private string $title,
        private string $key,
        private array $subPermissions = [],
    ) {
        if (trim($title) === '' || trim($key) === '') {
            throw new InvalidArgumentException('Module permission title and key must not be empty.');
        }
    }

    public function title(): string { return $this->title; }
    public function key(): string { return $this->key; }
    /** @return list<ModulePermission> */
    public function subPermissions(): array { return $this->subPermissions; }
}
