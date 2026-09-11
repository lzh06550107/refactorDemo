<?php

declare(strict_types=1);

namespace modules\module\domain;

use InvalidArgumentException;

final readonly class ModuleDefinition
{
    public function __construct(
        private string $name,
        private string $title,
        private string $version,
        private bool $system,
        private ModuleLifecycleStatus $status,
        private ModuleSupportMatrix $support,
        private bool $settingsEnabled = false,
        private bool $ruleFieldsEnabled = false,
        private array $customPermissions = [],
    ) {
        if (trim($name) === '' || trim($title) === '' || trim($version) === '') {
            throw new InvalidArgumentException('Module name, title and version must not be empty.');
        }
    }

    public function name(): string { return $this->name; }
    public function title(): string { return $this->title; }
    public function version(): string { return $this->version; }
    public function system(): bool { return $this->system; }
    public function status(): ModuleLifecycleStatus { return $this->status; }
    public function support(): ModuleSupportMatrix { return $this->support; }
    public function settingsEnabled(): bool { return $this->settingsEnabled; }
    public function ruleFieldsEnabled(): bool { return $this->ruleFieldsEnabled; }
    /** @return list<ModuleCustomPermission> */
    public function customPermissions(): array { return $this->customPermissions; }
}
