<?php

declare(strict_types=1);

use app\account\domain\AccountType;
use app\quota\compat\R20AccountQuotaSnapshotRepository;

$adapter = new R20AccountQuotaSnapshotRepository();
$legacy = [
    'user_group_maxaccount' => 5,
    'maxaccount' => 12,
    'extra_account' => 2,
    'store_buy_account' => 3,
    'account_num' => 7,
    'account_limit' => 5,
    'founder_account_limit' => 4,
    'store_account_limit' => 1,
    'vice_group_name' => '',
];

$snapshot = $adapter->fromLegacyResult($legacy, AccountType::OFFICIAL_ACCOUNT);
expectSame(5, $snapshot->groupLimit(), 'R20 base group account limit must be preserved');
expectSame(2, $snapshot->extraGroupLimit(), 'R20 create-group contribution must be derived from total max');
expectSame(2, $snapshot->extraLimit(), 'R20 users_extra_limit contribution must be preserved');
expectSame(3, $snapshot->purchasedAllowance(), 'R20 purchased allowance must be preserved');
expectSame(7, $snapshot->consumed(), 'R20 consumed account count must be preserved');
expectSame(5, $snapshot->localAvailable(), 'R20 final local account_limit must be preserved verbatim');
expectSame(null, $snapshot->parentAvailable(), 'founder limit must not be treated as a parent pool without vice_group_name');
expectSame(1, $snapshot->purchasedAvailableHint(), 'R20 store account hint must be preserved');

$legacy['vice_group_name'] = 'channel';
$withParent = $adapter->fromLegacyResult($legacy, AccountType::OFFICIAL_ACCOUNT);
expectSame(4, $withParent->parentAvailable(), 'vice-founder account must expose its parent quota limit');
