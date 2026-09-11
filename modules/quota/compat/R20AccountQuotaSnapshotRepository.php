<?php

declare(strict_types=1);

namespace modules\quota\compat;

use modules\account\domain\AccountType;
use modules\quota\domain\AccountQuotaSnapshot;
use InvalidArgumentException;

final class R20AccountQuotaSnapshotRepository
{
    private const SIGN_BY_TYPE = [
        AccountType::OFFICIAL_ACCOUNT->value => 'account',
        AccountType::WECHAT_MINI_PROGRAM->value => 'wxapp',
        AccountType::WEBAPP->value => 'webapp',
        AccountType::PHONEAPP->value => 'phoneapp',
        AccountType::ALIPAY_MINI_PROGRAM->value => 'aliapp',
        AccountType::BAIDU_MINI_PROGRAM->value => 'baiduapp',
        AccountType::TOUTIAO_MINI_PROGRAM->value => 'toutiaoapp',
    ];

    /**
     * Normalize the already-computed R20 permission_user_account_num() result.
     * Legacy globals and table calls remain outside the new domain.
     *
     * @param array<string,mixed> $legacyResult
     */
    public function fromLegacyResult(array $legacyResult, AccountType $accountType, bool $unlimited = false): AccountQuotaSnapshot
    {
        $sign = self::SIGN_BY_TYPE[$accountType->value] ?? throw new InvalidArgumentException('Unsupported account type.');
        $groupLimit = $this->int($legacyResult, 'user_group_max' . $sign);
        $totalMax = $this->int($legacyResult, 'max' . $sign);
        $extraLimit = $this->int($legacyResult, 'extra_' . $sign);
        $purchased = $this->int($legacyResult, 'store_buy_' . $sign);

        // R20 max{sign} = group + create-group extra + users_extra_limit + store purchase.
        $extraGroup = max($totalMax - $groupLimit - $extraLimit - $purchased, 0);

        return new AccountQuotaSnapshot(
            accountType: $accountType,
            groupLimit: $groupLimit,
            extraGroupLimit: $extraGroup,
            extraLimit: $extraLimit,
            purchasedAllowance: $purchased,
            consumed: $this->int($legacyResult, $sign . '_num'),
            localAvailable: $this->int($legacyResult, $sign . '_limit'),
            parentAvailable: trim((string) ($legacyResult['vice_group_name'] ?? '')) !== ''
                ? $this->int($legacyResult, 'founder_' . $sign . '_limit')
                : null,
            purchasedAvailableHint: $this->int($legacyResult, 'store_' . $sign . '_limit'),
            unlimited: $unlimited,
        );
    }

    /** @param array<string,mixed> $row */
    private function int(array $row, string $key): int
    {
        if (!array_key_exists($key, $row)) {
            throw new InvalidArgumentException('R20 quota snapshot missing ' . $key . '.');
        }
        return max((int) $row[$key], 0);
    }
}
