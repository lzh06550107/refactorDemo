<?php

declare(strict_types=1);

use app\miniapp\domain\MiniAppConnectionMode;
use app\miniapp\domain\MiniAppProviderAccount;

$manual = new MiniAppProviderAccount('tenant-1', 'account-1', 'wx-app-1', MiniAppConnectionMode::MANUAL, 'secret-ref-1', null);
assertSame('tenant-1', $manual->tenantId());
assertSame('account-1', $manual->accountId());
assertSame('wx-app-1', $manual->providerAppId());
assertSame('secret-ref-1', $manual->credentialRef());
assertSame(null, $manual->componentPlatformId());

$component = new MiniAppProviderAccount('tenant-1', 'account-2', 'wx-app-2', MiniAppConnectionMode::COMPONENT, null, 'component-1');
assertSame('component-1', $component->componentPlatformId());

assertThrows(
    fn () => new MiniAppProviderAccount('tenant-1', 'account-1', 'wx-app-1', MiniAppConnectionMode::MANUAL, null, null),
    InvalidArgumentException::class,
);
assertThrows(
    fn () => new MiniAppProviderAccount('tenant-1', 'account-1', 'wx-app-1', MiniAppConnectionMode::COMPONENT, 'should-not-exist', 'component-1'),
    InvalidArgumentException::class,
);
