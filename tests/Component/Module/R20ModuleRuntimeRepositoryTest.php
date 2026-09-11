<?php

declare(strict_types=1);

use modules\account\domain\LegacyAccountMapping;
use app\legacy\contract\LegacyDatabase;
use app\legacy\support\LegacySerializedValueDecoder;
use modules\module\compat\LegacyModuleAdapter;
use modules\module\domain\ModuleBindingType;
use modules\module\domain\ModuleLifecycleStatus;
use modules\module\infrastructure\R20ModuleRuntimeRepository;

final class RuntimeRepoFakeDb implements LegacyDatabase
{
    public function __construct(private array $tables) {}
    public function fetchOne(string $table, array $where): ?array
    {
        foreach ($this->fetchAll($table, $where) as $row) return $row;
        return null;
    }
    public function fetchAll(string $table, array $where): array
    {
        return array_values(array_filter($this->tables[$table] ?? [], static function (array $row) use ($where): bool {
            foreach ($where as $key => $value) if (($row[$key] ?? null) !== $value) return false;
            return true;
        }));
    }
}

$db = new RuntimeRepoFakeDb([
    'modules' => [
        ['name'=>'main_shop','title'=>'Main Shop','version'=>'1.0','account_support'=>2,'wxapp_support'=>0,'webapp_support'=>0,'phoneapp_support'=>0,'aliapp_support'=>0,'baiduapp_support'=>0,'toutiaoapp_support'=>0,'issystem'=>0,'settings'=>1,'isrulefields'=>0,'permissions'=>serialize([])],
        ['name'=>'plugin_coupon','title'=>'Coupon','version'=>'1.2','account_support'=>2,'wxapp_support'=>0,'webapp_support'=>0,'phoneapp_support'=>0,'aliapp_support'=>0,'baiduapp_support'=>0,'toutiaoapp_support'=>0,'issystem'=>0,'settings'=>0,'isrulefields'=>0,'permissions'=>serialize([])],
        ['name'=>'dead_mod','title'=>'Dead','version'=>'1.0','account_support'=>2,'wxapp_support'=>0,'webapp_support'=>0,'phoneapp_support'=>0,'aliapp_support'=>0,'baiduapp_support'=>0,'toutiaoapp_support'=>0,'issystem'=>0,'settings'=>0,'isrulefields'=>0,'permissions'=>serialize([])],
    ],
    'modules_recycle' => [
        ['name'=>'dead_mod','type'=>1,'account_support'=>1,'wxapp_support'=>0,'welcome_support'=>0,'webapp_support'=>0,'phoneapp_support'=>0,'aliapp_support'=>0,'baiduapp_support'=>0,'toutiaoapp_support'=>0],
    ],
    'uni_account_modules' => [
        ['uniacid'=>10,'module'=>'plugin_coupon','enabled'=>0,'displayorder'=>9,'shortcut'=>1,'module_shortcut'=>0,'settings'=>serialize(['color'=>'red'])],
    ],
    'modules_plugin' => [
        ['main_module'=>'main_shop','name'=>'plugin_coupon'],
    ],
    'modules_bindings' => [
        ['module'=>'plugin_coupon','entry'=>'menu','do'=>'orders','title'=>'Orders','url'=>'','call'=>'','multilevel'=>0,'parent'=>'','displayorder'=>5],
        ['module'=>'plugin_coupon','entry'=>'page','do'=>'','title'=>'Mini Page','url'=>'pages/index/index','call'=>'','multilevel'=>0,'parent'=>'','displayorder'=>1],
    ],
]);
$repo = new R20ModuleRuntimeRepository($db, new LegacySerializedValueDecoder(), new LegacyModuleAdapter());
$mapping = new LegacyAccountMapping('account-10','tenant-1',10,11,1);

$definition = $repo->definition('plugin_coupon');
expectSame('plugin_coupon', $definition?->name(), 'definition mapped');
expectSame(ModuleLifecycleStatus::ACTIVE, $definition?->status(), 'active module');
expectSame(ModuleLifecycleStatus::RECYCLED, $repo->definition('dead_mod')?->status(), 'fully recycled module');

$config = $repo->accountConfig($mapping, 'plugin_coupon');
expectSame(false, $config?->enabled(), 'account enabled overlay');
expectSame(['color'=>'red'], $config?->settings(), 'settings decoded');

$relation = $repo->pluginRelation('plugin_coupon');
expectSame('main_shop', $relation?->mainModule(), 'plugin main relation');
expectSame('plugin_coupon', $relation?->pluginModule(), 'plugin relation name');
expectSame(null, $repo->pluginRelation('main_shop'), 'main has no parent relation');

$bindings = $repo->bindings('plugin_coupon');
expectSame(2, count($bindings), 'bindings mapped');
expectSame(ModuleBindingType::MENU, $bindings[0]->entryType(), 'menu binding type');
expectSame('/module/plugin_coupon/menu/orders', $bindings[0]->routePath(), 'canonical static route');
expectSame(ModuleBindingType::PAGE, $bindings[1]->entryType(), 'page binding type');
expectSame('', $bindings[1]->do(), 'page may have empty do');
expectSame('pages/index/index', $bindings[1]->routePath(), 'page url preserved');
