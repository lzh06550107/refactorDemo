<?php

declare(strict_types=1);

namespace modules\iam\security;

use modules\iam\contract\SessionIdGenerator;

final class SecureSessionIdGenerator implements SessionIdGenerator
{
    public function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
