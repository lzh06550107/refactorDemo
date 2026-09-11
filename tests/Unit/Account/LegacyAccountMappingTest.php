<?php

declare(strict_types=1);

use modules\account\domain\AccountType;
use modules\account\domain\LegacyAccountMapping;

$mapping = new LegacyAccountMapping('account-7', 'tenant-3', 12, 34, 4);
expectSame('account-7', $mapping->accountId(), 'new account id');
expectSame('tenant-3', $mapping->tenantId(), 'new tenant id');
expectSame(12, $mapping->uniacid(), 'legacy uniacid');
expectSame(34, $mapping->acid(), 'legacy acid');
expectSame(4, $mapping->legacyType(), 'legacy type');
expectSame(AccountType::WECHAT_MINI_PROGRAM, $mapping->accountType(), 'mapped account type');

expectThrows(fn () => new LegacyAccountMapping('account-7', 'tenant-3', 0, 34, 4), InvalidArgumentException::class, 'uniacid must be positive');
expectThrows(fn () => new LegacyAccountMapping('account-7', 'tenant-3', 12, 0, 4), InvalidArgumentException::class, 'acid must be positive');
expectThrows(fn () => new LegacyAccountMapping('', 'tenant-3', 12, 34, 4), InvalidArgumentException::class, 'account id required');
expectThrows(fn () => new LegacyAccountMapping('account-7', '', 12, 34, 4), InvalidArgumentException::class, 'tenant id required');
expectThrows(fn () => new LegacyAccountMapping('account-7', 'tenant-3', 12, 34, 999), InvalidArgumentException::class, 'unsupported legacy type rejected');
