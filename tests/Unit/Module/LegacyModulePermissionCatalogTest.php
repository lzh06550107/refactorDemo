<?php

declare(strict_types=1);

use app\account\domain\AccountType;
use app\module\domain\LegacyModulePermissionCatalog;
use app\module\domain\ModuleBinding;
use app\module\domain\ModuleBindingType;
use app\module\domain\ModuleCustomPermission;
use app\module\domain\ModuleDefinition;
use app\module\domain\ModuleLifecycleStatus;
use app\module\domain\ModuleSupportMatrix;

$module = new ModuleDefinition(
    name: 'demo', title: 'Demo', version: '1', system: false,
    status: ModuleLifecycleStatus::ACTIVE,
    support: new ModuleSupportMatrix([AccountType::OFFICIAL_ACCOUNT]),
    settingsEnabled: true,
    ruleFieldsEnabled: true,
    customPermissions: [
        new ModuleCustomPermission('Export', 'export'),
        new ModuleCustomPermission('Download order', 'download', 'orders'),
    ],
);
$bindings = [
    new ModuleBinding('demo', ModuleBindingType::HOME, 'home', 'Home', '/home'),
    new ModuleBinding('demo', ModuleBindingType::PROFILE, 'profile', 'Profile', '/profile'),
    new ModuleBinding('demo', ModuleBindingType::SHORTCUT, 'shortcut', 'Shortcut', '/shortcut'),
    new ModuleBinding('demo', ModuleBindingType::COVER, 'welcome', 'Welcome Cover', '/cover'),
    new ModuleBinding('demo', ModuleBindingType::MENU, 'orders', 'Orders', '/orders'),
    new ModuleBinding('demo', ModuleBindingType::MENU, 'container', 'Container', null, null, true),
];

$catalog = new LegacyModulePermissionCatalog();
$permissions = $catalog->build($module, $bindings);
$byKey = [];
foreach ($permissions as $permission) {
    $byKey[$permission->key()] = $permission;
}
foreach ([
    'demo_settings', 'demo_rule', 'demo_home', 'demo_profile', 'demo_shortcut',
    'demo_cover_welcome', 'demo_menu_orders', 'demo_permission_export', 'demo_permission_download',
] as $key) {
    expectTrue(isset($byKey[$key]), 'catalog missing expected R20 permission ' . $key);
}
expectTrue(!isset($byKey['demo_menu_container']), 'multilevel menu container must not be a direct permission');
$sub = $byKey['demo_menu_orders']->subPermissions();
expectSame(1, count($sub), 'custom permission with parent must be attached as a sub-permission');
expectSame('demo_menu_orders_download', $sub[0]->key(), 'R20 parent sub-permission key must be preserved');
