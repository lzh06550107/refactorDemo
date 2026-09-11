<?php

declare(strict_types=1);

namespace modules\iam\domain;

use InvalidArgumentException;

final readonly class AdminCredential
{
    public function __construct(
        private AdminUser $user,
        private string $passwordHash,
    ) {
        if (trim($passwordHash) === '') {
            throw new InvalidArgumentException('Administrator password hash must not be empty.');
        }
    }

    public function user(): AdminUser
    {
        return $this->user;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }
}
