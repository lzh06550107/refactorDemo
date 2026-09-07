<?php

declare(strict_types=1);

use app\theme\domain\ThemeDefinition;
use app\theme\domain\ThemeVersion;

$theme = new ThemeDefinition('theme-1', 'default_mobile', 'Default Mobile', true);
expectSame('default_mobile', $theme->key(), 'theme key is preserved');
$version = new ThemeVersion('version-1', 'theme-1', '1.2.3', 'themes/default_mobile', str_repeat('a', 64));
expectSame('themes/default_mobile', $version->templateRoot(), 'theme version has a lexical template root');
expectThrows(fn () => new ThemeVersion('v', 'theme-1', '1', '../outside', str_repeat('a', 64)), InvalidArgumentException::class, 'theme root traversal must be rejected');
