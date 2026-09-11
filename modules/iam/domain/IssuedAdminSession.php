<?php

declare(strict_types=1);

namespace modules\iam\domain;

use InvalidArgumentException;

final readonly class IssuedAdminSession
{
    public function __construct(
        private AdminSession $session,
        private string $rawToken,
    ) {
        if ($rawToken === '') {
            throw new InvalidArgumentException('Raw administrator session token must not be empty.');
        }
    }

    public function session(): AdminSession
    {
        return $this->session;
    }

    public function rawToken(): string
    {
        return $this->rawToken;
    }
}
