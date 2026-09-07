<?php

declare(strict_types=1);

namespace app\module\domain;

final readonly class RuntimeModule
{
    public function __construct(
        private ModuleDefinition $definition,
        private bool $enabled,
        private int $displayOrder = 0,
        private bool $shortcut = false,
        private bool $moduleShortcut = false,
        private array $settings = [],
    ) {
    }

    public function definition(): ModuleDefinition { return $this->definition; }
    public function name(): string { return $this->definition->name(); }
    public function enabled(): bool { return $this->enabled; }
    public function displayOrder(): int { return $this->displayOrder; }
    public function shortcut(): bool { return $this->shortcut; }
    public function moduleShortcut(): bool { return $this->moduleShortcut; }
    public function settings(): array { return $this->settings; }
}
