<?php

declare(strict_types=1);

namespace app\openplatform\contract;

interface OpenPlatformSecretCipher
{
    /** @return array{ciphertext:string,keyVersion:string} */
    public function protect(string $plaintext): array;
    public function reveal(string $ciphertext, string $keyVersion): string;
}
