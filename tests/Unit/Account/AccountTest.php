<?php

declare(strict_types=1);

use app\account\domain\Account;
use app\account\domain\AccountStatus;
use app\account\domain\AccountType;

$account = new Account('account-1', 'tenant-1', '公众号 A', AccountType::OFFICIAL_ACCOUNT, AccountStatus::ACTIVE);
expectSame('account-1', $account->id(), 'account id');
expectSame('tenant-1', $account->tenantId(), 'account tenant');
expectSame('公众号 A', $account->name(), 'account name');
expectSame(AccountType::OFFICIAL_ACCOUNT, $account->type(), 'account type');
expectSame(AccountStatus::ACTIVE, $account->status(), 'account status');

expectThrows(fn () => new Account('', 'tenant-1', 'A', AccountType::WEBAPP, AccountStatus::ACTIVE), InvalidArgumentException::class, 'account id required');
expectThrows(fn () => new Account('account-1', '', 'A', AccountType::WEBAPP, AccountStatus::ACTIVE), InvalidArgumentException::class, 'tenant boundary required');
expectThrows(fn () => new Account('account-1', 'tenant-1', '', AccountType::WEBAPP, AccountStatus::ACTIVE), InvalidArgumentException::class, 'account name required');
