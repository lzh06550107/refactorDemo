<?php

declare(strict_types=1);

namespace app\common\context;

use InvalidArgumentException;

final readonly class Principal
{
    public function __construct(
        private string $id,
        private string $type,
    ) {
        if ($id === '' || $type === '') {
            throw new InvalidArgumentException('Principal id/type must not be empty.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function type(): string
    {
        return $this->type;
    }
}
