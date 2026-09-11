<?php

declare(strict_types=1);

namespace modules\module\contract;

use modules\account\domain\LegacyAccountMapping;
use modules\module\domain\AccountModuleConfig;
use modules\module\domain\ModuleBinding;
use modules\module\domain\ModuleDefinition;
use modules\module\domain\ModulePluginRelation;

interface ModuleRuntimeRepository
{
    public function definition(string $moduleName): ?ModuleDefinition;

    public function accountConfig(LegacyAccountMapping $mapping, string $moduleName): ?AccountModuleConfig;

    public function pluginRelation(string $moduleName): ?ModulePluginRelation;

    /** @return list<ModuleBinding> */
    public function bindings(string $moduleName): array;
}
