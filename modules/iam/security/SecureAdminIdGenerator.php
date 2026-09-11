<?php

declare(strict_types=1);

namespace modules\iam\security;

use modules\iam\contract\AdminIdGenerator;

final class SecureAdminIdGenerator implements AdminIdGenerator
{
    public function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
