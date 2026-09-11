<?php

declare(strict_types=1);

namespace modules\iam\security;

use modules\iam\contract\SessionTokenGenerator;

final class SecureSessionTokenGenerator implements SessionTokenGenerator
{
    public function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
