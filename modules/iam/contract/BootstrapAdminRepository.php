<?php

declare(strict_types=1);

namespace modules\iam\contract;

use modules\iam\domain\BootstrapAdminCreateResult;

interface BootstrapAdminRepository
{
    public function createFirst(
        string $id,
        string $username,
        string $passwordHash,
    ): BootstrapAdminCreateResult;
}
