<?php

declare(strict_types=1);

use modules\account\domain\AccountCapability;
use modules\account\domain\AccountType;
use modules\account\domain\LegacyAccountTypeMap;

$expected = [
    1 => AccountType::OFFICIAL_ACCOUNT,
    3 => AccountType::OFFICIAL_ACCOUNT,
    4 => AccountType::WECHAT_MINI_PROGRAM,
    7 => AccountType::WECHAT_MINI_PROGRAM,
    5 => AccountType::WEBAPP,
    6 => AccountType::PHONEAPP,
    11 => AccountType::ALIPAY_MINI_PROGRAM,
    12 => AccountType::BAIDU_MINI_PROGRAM,
    13 => AccountType::TOUTIAO_MINI_PROGRAM,
];
foreach ($expected as $legacyType => $type) {
    expectSame($type, LegacyAccountTypeMap::fromLegacyType($legacyType), 'legacy account type ' . $legacyType);
    expectTrue(in_array(AccountCapability::MODULE_RUNTIME, LegacyAccountTypeMap::capabilities($legacyType), true), 'all confirmed R20 account types support module runtime');
}

foreach ([4, 7, 6, 11, 12, 13] as $legacyType) {
    expectTrue(in_array(AccountCapability::VERSIONED_RELEASE, LegacyAccountTypeMap::capabilities($legacyType), true), 'R20 support_version type ' . $legacyType);
}
foreach ([1, 3, 5] as $legacyType) {
    expectTrue(!in_array(AccountCapability::VERSIONED_RELEASE, LegacyAccountTypeMap::capabilities($legacyType), true), 'R20 non-versioned type ' . $legacyType);
}

expectThrows(fn () => LegacyAccountTypeMap::fromLegacyType(999), InvalidArgumentException::class, 'unsupported legacy type rejected');
