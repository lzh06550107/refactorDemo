<?php

declare(strict_types=1);

namespace app\module\domain;

use InvalidArgumentException;

final readonly class ModuleBinding
{
    public function __construct(
        private string $moduleName,
        private ModuleBindingType $entryType,
        private string $do,
        private string $title,
        private ?string $routePath = null,
        private ?string $legacyCall = null,
        private bool $multilevel = false,
        private ?string $parent = null,
        private int $displayOrder = 0,
    ) {
        if (trim($moduleName) === '' || trim($title) === '') {
            throw new InvalidArgumentException('Module binding module and title must not be empty.');
        }
        if ($entryType !== ModuleBindingType::PAGE && trim($do) === '') {
            throw new InvalidArgumentException('Module binding do must not be empty except for page bindings.');
        }
        if ($routePath === null && $legacyCall === null && !$multilevel) {
            throw new InvalidArgumentException('Routable binding must have a route path or legacy call.');
        }
    }

    public function moduleName(): string { return $this->moduleName; }
    public function entryType(): ModuleBindingType { return $this->entryType; }
    public function do(): string { return $this->do; }
    public function title(): string { return $this->title; }
    public function routePath(): ?string { return $this->routePath; }
    public function legacyCall(): ?string { return $this->legacyCall; }
    public function multilevel(): bool { return $this->multilevel; }
    public function parent(): ?string { return $this->parent; }
    public function displayOrder(): int { return $this->displayOrder; }
}
