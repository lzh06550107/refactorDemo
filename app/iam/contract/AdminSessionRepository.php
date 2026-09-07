<?php

declare(strict_types=1);

namespace app\iam\contract;

use app\iam\domain\AdminSession;

interface AdminSessionRepository
{
    public function findByTokenHash(string $tokenHash): ?AdminSession;
}
