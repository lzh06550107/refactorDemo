<?php

declare(strict_types=1);

namespace modules\miniapp\contract;

use modules\miniapp\domain\MiniAppSession;

interface MiniAppSessionRepository
{
    public function insert(MiniAppSession $session): void;
    public function findByTokenHash(string $tokenHash): ?MiniAppSession;
}
