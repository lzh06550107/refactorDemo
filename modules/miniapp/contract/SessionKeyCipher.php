<?php

declare(strict_types=1);

namespace modules\miniapp\contract;

use modules\miniapp\domain\ProtectedSessionKey;

interface SessionKeyCipher
{
    public function protect(string $sessionKey): ProtectedSessionKey;
    public function reveal(ProtectedSessionKey $protected): string;
}
