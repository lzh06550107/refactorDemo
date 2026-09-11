<?php

declare(strict_types=1);

use modules\module\domain\ModulePluginRelation;
use modules\module\domain\RuntimeModuleContext;

$relation = new ModulePluginRelation('main_shop', 'plugin_coupon');
expectSame('main_shop', $relation->mainModule(), 'main module');
expectSame('plugin_coupon', $relation->pluginModule(), 'plugin module');
expectThrows(static fn () => new ModulePluginRelation('', 'plugin'), InvalidArgumentException::class, 'empty main rejected');
expectThrows(static fn () => new ModulePluginRelation('same', 'same'), InvalidArgumentException::class, 'self dependency rejected');

$reflection = new ReflectionClass(RuntimeModuleContext::class);
expectTrue($reflection->isReadOnly(), 'RuntimeModuleContext must be readonly');
