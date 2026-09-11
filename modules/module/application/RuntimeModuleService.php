<?php

declare(strict_types=1);

namespace modules\module\application;

use modules\account\domain\LegacyAccountMapping;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\module\contract\ModuleRuntimeRepository;
use modules\module\domain\BindingRouteDecision;
use modules\module\domain\BindingRuntimeRouter;
use modules\module\domain\ModuleBindingType;
use modules\module\domain\RuntimeModuleContext;
use modules\module\domain\RuntimeModuleResolver;

final class RuntimeModuleService
{
    public function __construct(
        private readonly ModuleRuntimeRepository $repository,
        private readonly RuntimeModuleResolver $resolver,
        private readonly BindingRuntimeRouter $bindingRouter,
    ) {
    }

    public function resolve(LegacyAccountMapping $account, string $moduleName, bool $enabledOnly = true): ?RuntimeModuleContext
    {
        return $this->resolveInternal($account, trim($moduleName), $enabledOnly, []);
    }

    public function route(
        LegacyAccountMapping $account,
        string $moduleName,
        ModuleBindingType $entryType,
        string $do,
    ): BindingRouteDecision {
        $context = $this->resolve($account, $moduleName, true);
        if ($context === null) {
            throw new AppException(ErrorCode::NOT_FOUND, 'Runnable module not found.', 404, ['module' => $moduleName]);
        }

        return $this->bindingRouter->resolve(
            $this->repository->bindings($moduleName),
            $moduleName,
            $entryType,
            $do,
        );
    }

    private function resolveInternal(
        LegacyAccountMapping $account,
        string $moduleName,
        bool $enabledOnly,
        array $visited,
    ): ?RuntimeModuleContext {
        if ($moduleName === '') {
            return null;
        }
        if (isset($visited[$moduleName])) {
            throw new AppException(ErrorCode::CONFLICT, 'Module plugin dependency cycle detected.', 409, ['module' => $moduleName]);
        }
        $visited[$moduleName] = true;

        $definition = $this->repository->definition($moduleName);
        if ($definition === null) {
            return null;
        }
        $runtime = $this->resolver->resolve(
            $definition,
            $this->repository->accountConfig($account, $moduleName),
            $account->accountType(),
            $enabledOnly,
        );
        if ($runtime === null) {
            return null;
        }

        $relation = $this->repository->pluginRelation($moduleName);
        if ($relation === null) {
            return new RuntimeModuleContext($runtime);
        }

        $mainContext = $this->resolveInternal($account, $relation->mainModule(), true, $visited);
        if ($mainContext === null) {
            return null;
        }

        return new RuntimeModuleContext($runtime, $mainContext->module(), $relation);
    }
}
