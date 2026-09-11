<?php

declare(strict_types=1);

namespace modules\account\domain;

use InvalidArgumentException;

final readonly class Account
{
    public function __construct(
        private string $id,
        private string $tenantId,
        private string $name,
        private AccountType $type,
        private AccountStatus $status,
    ) {
        if (trim($id) === '' || trim($tenantId) === '') {
            throw new InvalidArgumentException('Account and tenant identifiers must not be empty.');
        }
        if (trim($name) === '') {
            throw new InvalidArgumentException('Account name must not be empty.');
        }
    }

    public function id(): string { return $this->id; }
    public function tenantId(): string { return $this->tenantId; }
    public function name(): string { return $this->name; }
    public function type(): AccountType { return $this->type; }
    public function status(): AccountStatus { return $this->status; }
}
