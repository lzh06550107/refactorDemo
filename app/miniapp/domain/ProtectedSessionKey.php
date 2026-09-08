<?php

declare(strict_types=1);

namespace app\miniapp\domain;

use InvalidArgumentException;

final readonly class ProtectedSessionKey
{
    public function __construct(
        private string $ciphertext,
        private string $keyVersion,
    ) {
        if ($ciphertext === '' || trim($keyVersion) === '') {
            throw new InvalidArgumentException('Protected session key requires ciphertext and key version.');
        }
    }

    public function ciphertext(): string { return $this->ciphertext; }
    public function keyVersion(): string { return $this->keyVersion; }
}
