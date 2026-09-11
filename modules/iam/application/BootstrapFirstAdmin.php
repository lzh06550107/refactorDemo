<?php

declare(strict_types=1);

namespace modules\iam\application;

use InvalidArgumentException;
use modules\iam\contract\AdminIdGenerator;
use modules\iam\contract\BootstrapAdminRepository;
use modules\iam\domain\AdminUser;
use modules\iam\domain\AdminUserStatus;
use modules\iam\domain\BootstrapAdminCreateResult;
use RuntimeException;

final readonly class BootstrapFirstAdmin
{
    public function __construct(
        private BootstrapAdminRepository $repository,
        private AdminIdGenerator $ids,
    ) {
    }

    public function execute(string $username, string $password): AdminUser
    {
        $username = preg_replace('/^\s+|\s+$/u', '', $username) ?? '';
        if (preg_match('//u', $username) !== 1) {
            throw new InvalidArgumentException('Administrator username must be valid UTF-8.');
        }

        $usernameLength = mb_strlen($username, 'UTF-8');
        if (
            $usernameLength < 3
            || $usernameLength > 64
            || preg_match('/[\x00-\x1F\x7F]/u', $username) === 1
        ) {
            throw new InvalidArgumentException('Administrator username is invalid.');
        }

        if (
            preg_match('//u', $password) !== 1
            || mb_strlen($password, 'UTF-8') < 12
            || strlen($password) > 1024
        ) {
            throw new InvalidArgumentException('Administrator password does not satisfy bootstrap policy.');
        }

        $id = $this->ids->generate();
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($passwordHash) || $passwordHash === '') {
            throw new RuntimeException('Administrator password could not be hashed.');
        }

        $result = $this->repository->createFirst($id, $username, $passwordHash);
        if ($result === BootstrapAdminCreateResult::ALREADY_EXISTS) {
            throw new InitialAdminAlreadyExists('Initial administrator already exists.');
        }

        return new AdminUser($id, $username, AdminUserStatus::ACTIVE, null);
    }
}
