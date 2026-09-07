<?php

declare(strict_types=1);

namespace app\tenant\domain;

use InvalidArgumentException;

final readonly class Tenant
{
    public function __construct(
        private string $id,
        private string $name,
        private TenantStatus $status,
    ) {
        if (trim($id) === '') {
            throw new InvalidArgumentException('Tenant id must not be empty.');
        }
        if (trim($name) === '') {
            throw new InvalidArgumentException('Tenant name must not be empty.');
        }
    }

    public function id(): string { return $this->id; }
    public function name(): string { return $this->name; }
    public function status(): TenantStatus { return $this->status; }
}
