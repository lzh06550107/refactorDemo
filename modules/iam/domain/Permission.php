<?php

declare(strict_types=1);

namespace modules\iam\domain;

use InvalidArgumentException;

final readonly class Permission
{
    public function __construct(private string $key)
    {
        $key = trim($this->key);
        if ($key === '') {
            throw new InvalidArgumentException('Permission key must not be empty.');
        }
        if (preg_match('/^[A-Za-z0-9._:*\-]+$/', $key) !== 1) {
            throw new InvalidArgumentException('Permission key contains unsupported characters.');
        }
    }

    public function key(): string
    {
        return $this->key;
    }
}
