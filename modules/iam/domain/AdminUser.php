<?php

declare(strict_types=1);

namespace modules\iam\domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AdminUser
{
    public function __construct(
        private string $id,
        private string $username,
        private AdminUserStatus $status,
        private ?DateTimeImmutable $expiresAt,
    ) {
        if (trim($id) === '') {
            throw new InvalidArgumentException('Admin user id must not be empty.');
        }
        if (trim($username) === '') {
            throw new InvalidArgumentException('Admin username must not be empty.');
        }
    }

    public function id(): string { return $this->id; }
    public function username(): string { return $this->username; }
    public function status(): AdminUserStatus { return $this->status; }
    public function expiresAt(): ?DateTimeImmutable { return $this->expiresAt; }
    public function isExpired(DateTimeImmutable $now): bool { return $this->expiresAt !== null && $now >= $this->expiresAt; }
}
