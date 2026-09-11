<?php

declare(strict_types=1);

namespace modules\miniapp\contract;

interface MiniAppDataDecryptor
{
    public function decrypt(string $encryptedData, string $iv, string $sessionKey): string;
}
