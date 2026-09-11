<?php

declare(strict_types=1);

use modules\site\domain\Site;
use modules\site\domain\SiteStatus;

$default = new Site('site-1', 'tenant-1', 'account-1', 'Default site', SiteStatus::ENABLED, true, null, 7);
expectTrue($default->isEnabled(), 'default site should start enabled');
expectTrue($default->isDefault(), 'default flag should be preserved');
expectThrows(fn () => $default->disable(), LogicException::class, 'default site must not be disabled');

$secondary = new Site('site-2', 'tenant-1', 'account-1', 'Campaign', SiteStatus::ENABLED, false, null, 8);
$disabled = $secondary->disable();
expectSame(SiteStatus::DISABLED, $disabled->status(), 'secondary site can be disabled');
$released = $secondary->withActiveThemeRelease('release-1');
expectSame('release-1', $released->activeThemeReleaseId(), 'site should track active theme release');
expectSame(null, $secondary->activeThemeReleaseId(), 'site updates should be immutable');
