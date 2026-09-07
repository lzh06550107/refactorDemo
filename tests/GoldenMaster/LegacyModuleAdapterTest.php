<?php

declare(strict_types=1);

use app\account\domain\AccountType;
use app\module\compat\LegacyModuleAdapter;
use app\module\domain\ModuleLifecycleStatus;

$adapter = new LegacyModuleAdapter();
$definition = $adapter->definition([
    'name' => 'demo',
    'title' => 'Demo',
    'version' => '2.4.0',
    'issystem' => 0,
    'settings' => 1,
    'isrulefields' => 1,
    'account_support' => 2,
    'wxapp_support' => 1,
    'webapp_support' => 2,
    'phoneapp_support' => 1,
    'aliapp_support' => 2,
    'baiduapp_support' => 1,
    'toutiaoapp_support' => 2,
    'is_delete' => 0,
    'permissions' => [
        ['title' => 'Export', 'permission' => 'export'],
        ['title' => 'Download', 'permission' => 'download', 'parent' => 'orders'],
    ],
]);
expectSame('demo', $definition->name(), 'legacy modules.name must map to definition name');
expectSame(ModuleLifecycleStatus::ACTIVE, $definition->status(), 'is_delete=0 must map active');
expectTrue($definition->support()->supports(AccountType::OFFICIAL_ACCOUNT), 'account_support=2 must support official account');
expectTrue($definition->support()->supports(AccountType::WEBAPP), 'webapp_support=2 must support webapp');
expectTrue($definition->support()->supports(AccountType::ALIPAY_MINI_PROGRAM), 'aliapp_support=2 must support Alipay mini app');
expectTrue($definition->support()->supports(AccountType::TOUTIAO_MINI_PROGRAM), 'toutiaoapp_support=2 must support Toutiao mini app');
expectTrue($definition->support()->supports(AccountType::WECHAT_MINI_PROGRAM), 'R20 app account accepts account_support=2 even when wxapp_support=1');
expectSame(2, count($definition->customPermissions()), 'decoded R20 custom permissions must map to typed specs');

expectSame(null, $adapter->accountConfig('acct-1', 'tenant-1', 'demo', null), 'missing uni_account_modules row must stay absent');
$config = $adapter->accountConfig('acct-1', 'tenant-1', 'demo', [
    'module' => 'demo',
    'enabled' => 0,
    'displayorder' => '7',
    'shortcut' => 1,
    'module_shortcut' => 0,
    'settings' => ['color' => 'blue'],
]);
expectTrue($config !== null && !$config->enabled(), 'legacy enabled=0 must map false');
expectSame(7, $config?->displayOrder(), 'displayorder must map as integer');
expectTrue($config?->shortcut() === true, 'shortcut must map as bool');
expectSame(['color' => 'blue'], $config?->settings(), 'settings array must be preserved');

$recycled = $adapter->definition([
    'name' => 'old', 'title' => 'Old', 'version' => '1', 'issystem' => 1,
    'account_support' => 2, 'is_delete' => 1,
]);
expectSame(ModuleLifecycleStatus::RECYCLED, $recycled->status(), 'R20 is_delete must map recycled');
expectTrue($recycled->system(), 'R20 issystem must map system flag');
