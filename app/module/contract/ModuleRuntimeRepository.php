<?php

declare(strict_types=1);

namespace app\module\contract;

use app\account\domain\LegacyAccountMapping;
use app\module\domain\AccountModuleConfig;
use app\module\domain\ModuleBinding;
use app\module\domain\ModuleDefinition;
use app\module\domain\ModulePluginRelation;

interface ModuleRuntimeRepository
{
    public function definition(string $moduleName): ?ModuleDefinition;

    public function accountConfig(LegacyAccountMapping $mapping, string $moduleName): ?AccountModuleConfig;

    public function pluginRelation(string $moduleName): ?ModulePluginRelation;

    /** @return list<ModuleBinding> */
    public function bindings(string $moduleName): array;
}
