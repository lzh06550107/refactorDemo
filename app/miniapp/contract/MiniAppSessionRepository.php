<?php

declare(strict_types=1);

namespace app\miniapp\contract;

use app\miniapp\domain\MiniAppSession;

interface MiniAppSessionRepository
{
    public function insert(MiniAppSession $session): void;
    public function findByTokenHash(string $tokenHash): ?MiniAppSession;
}
