<?php

declare(strict_types=1);

namespace modules\module\domain;

use InvalidArgumentException;

final readonly class ModulePluginRelation
{
    public function __construct(private string $mainModule, private string $pluginModule)
    {
        if (trim($mainModule) === '' || trim($pluginModule) === '') {
            throw new InvalidArgumentException('Main and plugin module names must not be empty.');
        }
        if ($mainModule === $pluginModule) {
            throw new InvalidArgumentException('A module cannot be its own plugin main module.');
        }
    }

    public function mainModule(): string { return $this->mainModule; }
    public function pluginModule(): string { return $this->pluginModule; }
}
