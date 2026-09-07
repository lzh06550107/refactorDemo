<?php

declare(strict_types=1);

namespace app\module\domain;

use app\common\error\AppException;
use app\common\error\ErrorCode;

final class BindingRuntimeRouter
{
    /** @param list<ModuleBinding> $bindings */
    public function resolve(array $bindings, string $moduleName, ModuleBindingType $entryType, string $do): BindingRouteDecision
    {
        foreach ($bindings as $binding) {
            if ($binding->moduleName() !== $moduleName || $binding->entryType() !== $entryType || $binding->do() !== $do) {
                continue;
            }

            return new BindingRouteDecision(
                $binding->legacyCall() !== null && $binding->legacyCall() !== ''
                    ? BindingRouteKind::LEGACY_DELEGATE
                    : BindingRouteKind::NEW_RUNTIME,
                $binding,
            );
        }

        throw new AppException(ErrorCode::NOT_FOUND, 'Module binding not found.', 404, [
            'module' => $moduleName,
            'entry_type' => $entryType->value,
            'do' => $do,
        ]);
    }
}
