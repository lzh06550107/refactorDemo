<?php

declare(strict_types=1);

namespace modules\iam\application;

use modules\iam\contract\AdminSessionStore;
use modules\iam\security\SessionTokenHasher;

final readonly class LogoutAdminSession
{
    public function __construct(
        private AdminSessionStore $store,
        private SessionTokenHasher $tokenHasher,
    ) {
    }

    public function execute(string $rawToken): void
    {
        if (trim($rawToken) === '') {
            return;
        }

        $this->store->deleteByTokenHash($this->tokenHasher->hash($rawToken));
    }
}
