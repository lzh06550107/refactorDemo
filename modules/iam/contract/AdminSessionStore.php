<?php

declare(strict_types=1);

namespace modules\iam\contract;

use modules\iam\domain\AdminSession;

interface AdminSessionStore
{
    public function save(AdminSession $session): void;

    public function deleteByTokenHash(string $tokenHash): void;
}
