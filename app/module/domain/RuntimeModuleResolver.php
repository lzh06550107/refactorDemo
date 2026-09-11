<?php

declare(strict_types=1);

namespace app\module\domain;

use modules\account\domain\AccountType;
use InvalidArgumentException;

final class RuntimeModuleResolver
{
    public function resolve(
        ModuleDefinition $definition,
        ?AccountModuleConfig $config,
        AccountType $accountType,
        bool $enabledOnly = true,
    ): ?RuntimeModule {
        if (!$definition->support()->supports($accountType)) {
            return null;
        }
        if ($enabledOnly && $definition->status() === ModuleLifecycleStatus::RECYCLED) {
            return null;
        }
        if ($config !== null && $config->moduleName() !== $definition->name()) {
            throw new InvalidArgumentException('Account module config does not match module definition.');
        }

        $enabled = $definition->system() || $config === null || $config->enabled();
        if ($enabledOnly && !$enabled) {
            return null;
        }

        return new RuntimeModule(
            definition: $definition,
            enabled: $enabled,
            displayOrder: $config?->displayOrder() ?? 0,
            shortcut: $config?->shortcut() ?? false,
            moduleShortcut: $config?->moduleShortcut() ?? false,
            settings: $config?->settings() ?? [],
        );
    }
}
