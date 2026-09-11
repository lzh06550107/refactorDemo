<?php

declare(strict_types=1);

namespace modules\iam\contract;

use modules\iam\domain\AdminUser;

interface AdminUserRepository
{
    public function findById(string $id): ?AdminUser;
}
