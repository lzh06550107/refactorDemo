<?php

declare(strict_types=1);

namespace modules\iam\application;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use DateTimeImmutable;
use modules\iam\contract\AdminCredentialRepository;
use modules\iam\domain\AdminUser;
use modules\iam\domain\AdminUserStatus;

final readonly class AuthenticateAdmin
{
    public function __construct(private AdminCredentialRepository $repository)
    {
    }

    public function execute(string $username, string $password, DateTimeImmutable $now): AdminUser
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            throw $this->unauthorized();
        }

        $credential = $this->repository->findByUsername($username);
        if (
            $credential === null
            || !password_verify($password, $credential->passwordHash())
            || $credential->user()->status() !== AdminUserStatus::ACTIVE
            || $credential->user()->isExpired($now)
        ) {
            throw $this->unauthorized();
        }

        return $credential->user();
    }

    private function unauthorized(): AppException
    {
        return new AppException(
            ErrorCode::UNAUTHORIZED,
            'Administrator credentials are invalid.',
            401,
        );
    }
}
