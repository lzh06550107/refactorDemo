<?php

declare(strict_types=1);

namespace modules\theme\contract;

use modules\theme\domain\ResolvedThemePage;

interface ThemePackageRepository
{
    public function resolve(string $themeKey, string $pageKey): ResolvedThemePage;
}
