<?php

declare(strict_types=1);

namespace app\common\security;

use InvalidArgumentException;
use Stringable;

final readonly class SecretValue implements Stringable
{
    public function __construct(private string $value)
    {
        if ($value === '') {
            throw new InvalidArgumentException('Secret value must not be empty.');
        }
    }

    public function reveal(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return '[REDACTED]';
    }

    public function __debugInfo(): array
    {
        return ['value' => '[REDACTED]'];
    }
}
