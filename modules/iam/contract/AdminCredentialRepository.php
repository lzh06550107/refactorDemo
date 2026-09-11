<?php

declare(strict_types=1);

namespace modules\iam\contract;

use modules\iam\domain\AdminCredential;

interface AdminCredentialRepository
{
    public function findByUsername(string $username): ?AdminCredential;
}
