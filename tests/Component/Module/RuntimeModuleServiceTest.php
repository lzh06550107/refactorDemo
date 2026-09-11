<?php

declare(strict_types=1);

use modules\account\domain\AccountType;
use modules\account\domain\LegacyAccountMapping;
use app\common\error\AppException;
use app\module\application\RuntimeModuleService;
use app\module\contract\ModuleRuntimeRepository;
use app\module\domain\AccountModuleConfig;
use app\module\domain\BindingRuntimeRouter;
use app\module\domain\ModuleBinding;
use app\module\domain\ModuleBindingType;
use app\module\domain\ModuleDefinition;
use app\module\domain\ModuleLifecycleStatus;
use app\module\domain\ModulePluginRelation;
use app\module\domain\ModuleSupportMatrix;
use app\module\domain\RuntimeModuleResolver;

final class RuntimeServiceFakeRepo implements ModuleRuntimeRepository
{
    public function __construct(
        private array $definitions,
        private array $configs = [],
        private array $relations = [],
        private array $bindingsByModule = [],
    ) {}

    public function definition(string $moduleName): ?ModuleDefinition { return $this->definitions[$moduleName] ?? null; }
    public function accountConfig(LegacyAccountMapping $mapping, string $moduleName): ?AccountModuleConfig { return $this->configs[$moduleName] ?? null; }
    public function pluginRelation(string $moduleName): ?ModulePluginRelation { return $this->relations[$moduleName] ?? null; }
    public function bindings(string $moduleName): array { return $this->bindingsByModule[$moduleName] ?? []; }
}

$def = static fn (string $name): ModuleDefinition => new ModuleDefinition(
    $name,
    ucfirst($name),
    '1.0',
    false,
    ModuleLifecycleStatus::ACTIVE,
    new ModuleSupportMatrix([AccountType::OFFICIAL_ACCOUNT]),
);
$config = static fn (string $name, bool $enabled): AccountModuleConfig => new AccountModuleConfig('account-10', 'tenant-1', $name, $enabled);
$account = new LegacyAccountMapping('account-10', 'tenant-1', 10, 11, 1);

$repo = new RuntimeServiceFakeRepo(
    ['main'=>$def('main'), 'plugin'=>$def('plugin'), 'normal'=>$def('normal')],
    ['main'=>$config('main', false), 'plugin'=>$config('plugin', true)],
    ['plugin'=>new ModulePluginRelation('main', 'plugin')],
    ['normal'=>[new ModuleBinding('normal', ModuleBindingType::MENU, 'orders', 'Orders', '/module/normal/menu/orders')]],
);
$service = new RuntimeModuleService($repo, new RuntimeModuleResolver(), new BindingRuntimeRouter());

expectSame(null, $service->resolve($account, 'plugin'), 'plugin unavailable when main module is disabled');
expectSame('normal', $service->resolve($account, 'normal')?->module()->name(), 'normal module resolves');
$route = $service->route($account, 'normal', ModuleBindingType::MENU, 'orders');
expectSame('/module/normal/menu/orders', $route->routePath(), 'binding route resolved after runtime gate');
expectThrows(static fn () => $service->route($account, 'missing', ModuleBindingType::MENU, 'x'), AppException::class, 'missing runtime gives stable application error');
