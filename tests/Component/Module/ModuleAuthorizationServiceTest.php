<?php

declare(strict_types=1);

use app\account\domain\AccountType;
use app\account\domain\LegacyAccountMapping;
use app\iam\domain\LegacyPermissionAssignment;
use app\iam\domain\LegacyPermissionPolicy;
use app\iam\domain\Permission;
use app\module\application\ModuleAuthorizationService;
use app\module\application\RuntimeModuleService;
use app\module\contract\ModulePermissionRepository;
use app\module\contract\ModuleRuntimeRepository;
use app\module\domain\AccountModuleConfig;
use app\module\domain\BindingRuntimeRouter;
use app\module\domain\ModuleDefinition;
use app\module\domain\ModuleLifecycleStatus;
use app\module\domain\ModulePluginRelation;
use app\module\domain\ModuleSupportMatrix;
use app\module\domain\RuntimeModuleResolver;

final class AuthorizationRuntimeFakeRepo implements ModuleRuntimeRepository
{
    public function __construct(private ModuleDefinition $definition, private bool $enabled) {}
    public function definition(string $moduleName): ?ModuleDefinition { return $moduleName === 'demo' ? $this->definition : null; }
    public function accountConfig(LegacyAccountMapping $mapping, string $moduleName): ?AccountModuleConfig
    {
        return $moduleName === 'demo' ? new AccountModuleConfig($mapping->accountId(), $mapping->tenantId(), 'demo', $this->enabled) : null;
    }
    public function pluginRelation(string $moduleName): ?ModulePluginRelation { return null; }
    public function bindings(string $moduleName): array { return []; }
}

final class AuthorizationPermissionFakeRepo implements ModulePermissionRepository
{
    public function __construct(private LegacyPermissionAssignment $assignment) {}
    public function assignment(int $legacyUid, LegacyAccountMapping $account, string $moduleName): LegacyPermissionAssignment { return $this->assignment; }
}

$definition = new ModuleDefinition('demo', 'Demo', '1.0', false, ModuleLifecycleStatus::ACTIVE, new ModuleSupportMatrix([AccountType::OFFICIAL_ACCOUNT]));
$account = new LegacyAccountMapping('account-10', 'tenant-1', 10, 11, 1);
$permission = new Permission('demo_menu_orders');

$runtime = new RuntimeModuleService(new AuthorizationRuntimeFakeRepo($definition, false), new RuntimeModuleResolver(), new BindingRuntimeRouter());
$auth = new ModuleAuthorizationService($runtime, new AuthorizationPermissionFakeRepo(LegacyPermissionAssignment::all()), new LegacyPermissionPolicy());
expectSame(false, $auth->allows(7, $account, 'demo', $permission, true), 'explicit permission cannot resurrect disabled runtime');

$runtime = new RuntimeModuleService(new AuthorizationRuntimeFakeRepo($definition, true), new RuntimeModuleResolver(), new BindingRuntimeRouter());
$auth = new ModuleAuthorizationService($runtime, new AuthorizationPermissionFakeRepo(LegacyPermissionAssignment::roleDefault()), new LegacyPermissionPolicy());
expectSame(true, $auth->allows(7, $account, 'demo', $permission, true), 'role-default assignment uses caller role decision');
expectSame(false, $auth->allows(7, $account, 'demo', $permission, false), 'role-default assignment can deny');

$auth = new ModuleAuthorizationService($runtime, new AuthorizationPermissionFakeRepo(LegacyPermissionAssignment::explicit(['demo_menu_orders'])), new LegacyPermissionPolicy());
expectSame(true, $auth->allows(7, $account, 'demo', $permission, false), 'explicit module permission authorizes runnable module');
