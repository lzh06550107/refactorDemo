<?php

declare(strict_types=1);

namespace modules\iam\security;

use app\common\security\SecretValue;
use InvalidArgumentException;

final readonly class SessionTokenHasher
{
    public function __construct(private SecretValue $pepper)
    {
    }

    public function hash(string $token): string
    {
        if ($token === '') {
            throw new InvalidArgumentException('Session token must not be empty.');
        }

        return hash_hmac('sha256', $token, $this->pepper->reveal());
    }
}
