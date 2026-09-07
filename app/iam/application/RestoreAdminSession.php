<?php

declare(strict_types=1);

namespace app\iam\application;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\iam\contract\AdminSessionRepository;
use app\iam\domain\AdminSession;
use app\iam\security\SessionTokenHasher;
use DateTimeImmutable;

final readonly class RestoreAdminSession
{
    public function __construct(
        private AdminSessionRepository $repository,
        private SessionTokenHasher $tokenHasher,
    ) {
    }

    public function execute(string $rawToken, DateTimeImmutable $now): AdminSession
    {
        if ($rawToken === '') {
            throw $this->unauthorized();
        }

        $session = $this->repository->findByTokenHash($this->tokenHasher->hash($rawToken));
        if ($session === null || $session->isExpired($now)) {
            throw $this->unauthorized();
        }

        return $session;
    }

    private function unauthorized(): AppException
    {
        return new AppException(ErrorCode::UNAUTHORIZED, 'Administrator session is invalid or expired.', 401);
    }
}
