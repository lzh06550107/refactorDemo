<?php

declare(strict_types=1);

use app\iam\domain\AdminUser;
use app\iam\domain\AdminUserStatus;

$user = new AdminUser(
    'user-1',
    'alice',
    AdminUserStatus::ACTIVE,
    new DateTimeImmutable('2026-12-31T23:59:59+00:00'),
);
expectSame('user-1', $user->id(), 'admin user id');
expectSame('alice', $user->username(), 'admin username');
expectSame(AdminUserStatus::ACTIVE, $user->status(), 'admin user status');
expectTrue(!$user->isExpired(new DateTimeImmutable('2026-09-07T00:00:00+00:00')), 'future expiry is active');
expectTrue($user->isExpired(new DateTimeImmutable('2027-01-01T00:00:00+00:00')), 'past expiry is expired');

$unlimited = new AdminUser('user-2', 'bob', AdminUserStatus::ACTIVE, null);
expectTrue(!$unlimited->isExpired(new DateTimeImmutable('2099-01-01T00:00:00+00:00')), 'null expiry is unlimited');

expectThrows(fn () => new AdminUser('', 'alice', AdminUserStatus::ACTIVE, null), InvalidArgumentException::class, 'admin user id required');
expectThrows(fn () => new AdminUser('user-1', '', AdminUserStatus::ACTIVE, null), InvalidArgumentException::class, 'admin username required');
