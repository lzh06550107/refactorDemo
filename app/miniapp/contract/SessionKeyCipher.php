<?php

declare(strict_types=1);

namespace app\miniapp\contract;

use app\miniapp\domain\ProtectedSessionKey;

interface SessionKeyCipher
{
    public function protect(string $sessionKey): ProtectedSessionKey;
    public function reveal(ProtectedSessionKey $protected): string;
}
