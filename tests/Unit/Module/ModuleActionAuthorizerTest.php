<?php

declare(strict_types=1);

use modules\account\domain\AccountType;
use modules\iam\domain\LegacyPermissionAssignment;
use modules\iam\domain\Permission;
use modules\module\domain\ModuleActionAuthorizer;
use modules\module\domain\ModuleDefinition;
use modules\module\domain\ModuleLifecycleStatus;
use modules\module\domain\ModuleSupportMatrix;
use modules\module\domain\RuntimeModule;

$authorizer = new ModuleActionAuthorizer();
$activeDefinition = new ModuleDefinition(
    'demo', 'Demo', '1', false, ModuleLifecycleStatus::ACTIVE,
    new ModuleSupportMatrix([AccountType::OFFICIAL_ACCOUNT]),
);
$active = new RuntimeModule($activeDefinition, true);
$permission = new Permission('demo_menu_orders');

expectTrue($authorizer->allows($active, $permission, LegacyPermissionAssignment::roleDefault(), true), 'absent users_permission row must use role default after module availability passes');
expectTrue($authorizer->allows($active, $permission, LegacyPermissionAssignment::all(), false), 'explicit all must allow active module action');
expectTrue(!$authorizer->allows($active, $permission, LegacyPermissionAssignment::explicit(['demo_menu_other']), true), 'explicit missing module permission must deny');

$disabled = new RuntimeModule($activeDefinition, false);
expectTrue(!$authorizer->allows($disabled, $permission, LegacyPermissionAssignment::all(), true), 'disabled module must deny before user permission');
$recycledDefinition = new ModuleDefinition(
    'old', 'Old', '1', false, ModuleLifecycleStatus::RECYCLED,
    new ModuleSupportMatrix([AccountType::OFFICIAL_ACCOUNT]),
);
expectTrue(!$authorizer->allows(new RuntimeModule($recycledDefinition, true), new Permission('old_menu_x'), LegacyPermissionAssignment::all(), true), 'recycled module must deny before user permission');
