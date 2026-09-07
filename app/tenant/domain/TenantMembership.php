<?php

declare(strict_types=1);

namespace app\tenant\domain;

use InvalidArgumentException;

final readonly class TenantMembership
{
    public function __construct(
        private string $tenantId,
        private string $userId,
        private TenantRole $role,
    ) {
        if (trim($tenantId) === '' || trim($userId) === '') {
            throw new InvalidArgumentException('Tenant membership identifiers must not be empty.');
        }
    }

    public function tenantId(): string { return $this->tenantId; }
    public function userId(): string { return $this->userId; }
    public function role(): TenantRole { return $this->role; }
}
