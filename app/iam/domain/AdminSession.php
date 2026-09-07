<?php

declare(strict_types=1);

namespace app\iam\domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AdminSession
{
    public function __construct(
        private string $id,
        private string $userId,
        private string $tokenHash,
        private DateTimeImmutable $issuedAt,
        private DateTimeImmutable $expiresAt,
    ) {
        if (trim($id) === '' || trim($userId) === '') {
            throw new InvalidArgumentException('Session and user identifiers must not be empty.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $tokenHash) !== 1) {
            throw new InvalidArgumentException('Session token hash must be lowercase SHA-256 hex.');
        }
        if ($expiresAt <= $issuedAt) {
            throw new InvalidArgumentException('Session expiry must be after issue time.');
        }
    }

    public function id(): string { return $this->id; }
    public function userId(): string { return $this->userId; }
    public function tokenHash(): string { return $this->tokenHash; }
    public function issuedAt(): DateTimeImmutable { return $this->issuedAt; }
    public function expiresAt(): DateTimeImmutable { return $this->expiresAt; }
    public function isExpired(DateTimeImmutable $now): bool { return $now >= $this->expiresAt; }
}
