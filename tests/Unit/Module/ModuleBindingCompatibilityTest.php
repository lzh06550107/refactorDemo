<?php

declare(strict_types=1);

use app\module\domain\ModuleBinding;
use app\module\domain\ModuleBindingType;

$page = new ModuleBinding('demo', ModuleBindingType::PAGE, '', 'Page', 'pages/index/index');
expectSame('', $page->do(), 'page allows empty do');
expectSame(ModuleBindingType::WEBAPP, ModuleBindingType::from('webapp'), 'webapp entry supported');
expectSame(ModuleBindingType::PHONEAPP, ModuleBindingType::from('phoneapp'), 'phoneapp entry supported');
expectThrows(static fn () => new ModuleBinding('demo', ModuleBindingType::MENU, '', 'Menu', '/x'), InvalidArgumentException::class, 'non-page do remains required');
