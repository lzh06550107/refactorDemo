<?php

declare(strict_types=1);

namespace modules\account\domain;

use InvalidArgumentException;

final class LegacyAccountTypeMap
{
    public static function fromLegacyType(int $legacyType): AccountType
    {
        return match ($legacyType) {
            1, 3 => AccountType::OFFICIAL_ACCOUNT,
            4, 7 => AccountType::WECHAT_MINI_PROGRAM,
            5 => AccountType::WEBAPP,
            6 => AccountType::PHONEAPP,
            11 => AccountType::ALIPAY_MINI_PROGRAM,
            12 => AccountType::BAIDU_MINI_PROGRAM,
            13 => AccountType::TOUTIAO_MINI_PROGRAM,
            default => throw new InvalidArgumentException('Unsupported R20 account.type: ' . $legacyType),
        };
    }

    /** @return list<AccountCapability> */
    public static function capabilities(int $legacyType): array
    {
        self::fromLegacyType($legacyType);
        $capabilities = [AccountCapability::MODULE_RUNTIME];
        if (in_array($legacyType, [4, 7, 6, 11, 12, 13], true)) {
            $capabilities[] = AccountCapability::VERSIONED_RELEASE;
        }
        return $capabilities;
    }
}
