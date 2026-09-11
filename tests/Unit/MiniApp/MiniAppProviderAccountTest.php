<?php

declare(strict_types=1);

use modules\miniapp\domain\MiniAppConnectionMode;
use modules\miniapp\domain\MiniAppProviderAccount;

$manual = new MiniAppProviderAccount('tenant-1', 'account-1', 'wx-app-1', MiniAppConnectionMode::MANUAL, 'secret-ref-1', null);
expectSame('tenant-1', $manual->tenantId(), 'manual provider tenant');
expectSame('account-1', $manual->accountId(), 'manual provider account');
expectSame('wx-app-1', $manual->providerAppId(), 'manual provider appid');
expectSame('secret-ref-1', $manual->credentialRef(), 'manual provider credential ref');
expectSame(null, $manual->componentPlatformId(), 'manual provider has no component platform');

$component = new MiniAppProviderAccount('tenant-1', 'account-2', 'wx-app-2', MiniAppConnectionMode::COMPONENT, null, 'component-1');
expectSame('component-1', $component->componentPlatformId(), 'component provider platform');

expectThrows(
    fn () => new MiniAppProviderAccount('tenant-1', 'account-1', 'wx-app-1', MiniAppConnectionMode::MANUAL, null, null),
    InvalidArgumentException::class,
    'manual provider requires credential ref',
);
expectThrows(
    fn () => new MiniAppProviderAccount('tenant-1', 'account-1', 'wx-app-1', MiniAppConnectionMode::COMPONENT, 'should-not-exist', 'component-1'),
    InvalidArgumentException::class,
    'component provider forbids manual credential ref',
);
