<?php

declare(strict_types=1);

namespace app\member\domain;

use InvalidArgumentException;

final readonly class Member
{
    public function __construct(
        private string $id,
        private string $tenantId,
        private MemberStatus $status = MemberStatus::ACTIVE,
        private ?string $displayName = null,
        private ?string $avatarUrl = null,
    ) {
        if (trim($id) === '' || trim($tenantId) === '') {
            throw new InvalidArgumentException('Member id and tenant id must not be empty.');
        }
    }

    public function id(): string { return $this->id; }
    public function tenantId(): string { return $this->tenantId; }
    public function status(): MemberStatus { return $this->status; }
    public function displayName(): ?string { return $this->displayName; }
    public function avatarUrl(): ?string { return $this->avatarUrl; }
}
