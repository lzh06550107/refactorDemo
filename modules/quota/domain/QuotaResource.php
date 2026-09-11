<?php

declare(strict_types=1);

namespace modules\quota\domain;

use modules\account\domain\AccountType;
use InvalidArgumentException;

final readonly class QuotaResource
{
    public function __construct(private string $key)
    {
        if (trim($key) === '') {
            throw new InvalidArgumentException('Quota resource key must not be empty.');
        }
    }

    public static function accountCreate(AccountType $type): self
    {
        return new self('account_create:' . $type->value);
    }

    public function key(): string { return $this->key; }
}
