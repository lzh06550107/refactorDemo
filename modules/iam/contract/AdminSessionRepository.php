<?php

declare(strict_types=1);

namespace modules\iam\contract;

use modules\iam\domain\AdminSession;

interface AdminSessionRepository
{
    public function findByTokenHash(string $tokenHash): ?AdminSession;
}
