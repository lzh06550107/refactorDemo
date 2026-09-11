<?php

declare(strict_types=1);

use modules\module\domain\ModuleBinding;
use modules\module\domain\ModuleBindingType;

$page = new ModuleBinding('demo', ModuleBindingType::PAGE, '', 'Page', 'pages/index/index');
expectSame('', $page->do(), 'page allows empty do');
expectSame(ModuleBindingType::WEBAPP, ModuleBindingType::from('webapp'), 'webapp entry supported');
expectSame(ModuleBindingType::PHONEAPP, ModuleBindingType::from('phoneapp'), 'phoneapp entry supported');
expectThrows(static fn () => new ModuleBinding('demo', ModuleBindingType::MENU, '', 'Menu', '/x'), InvalidArgumentException::class, 'non-page do remains required');
