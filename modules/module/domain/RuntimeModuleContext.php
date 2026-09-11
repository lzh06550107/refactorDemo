<?php

declare(strict_types=1);

namespace modules\module\domain;

use InvalidArgumentException;

final readonly class RuntimeModuleContext
{
    public function __construct(
        private RuntimeModule $module,
        private ?RuntimeModule $mainModule = null,
        private ?ModulePluginRelation $pluginRelation = null,
    ) {
        if ($pluginRelation === null) {
            if ($mainModule !== null) {
                throw new InvalidArgumentException('Main runtime requires a plugin relation.');
            }
            return;
        }
        if ($pluginRelation->pluginModule() !== $module->name()) {
            throw new InvalidArgumentException('Plugin relation does not match runtime module.');
        }
        if ($mainModule === null || $pluginRelation->mainModule() !== $mainModule->name()) {
            throw new InvalidArgumentException('Plugin relation requires its resolved main module.');
        }
    }

    public function module(): RuntimeModule { return $this->module; }
    public function mainModule(): ?RuntimeModule { return $this->mainModule; }
    public function pluginRelation(): ?ModulePluginRelation { return $this->pluginRelation; }
    public function isPlugin(): bool { return $this->pluginRelation !== null; }
}
