<?php

declare(strict_types=1);

namespace modules\miniapp\domain;

final readonly class MiniAppEncryptedProfile
{
    public function __construct(private array $data)
    {
    }

    public function data(): array
    {
        return $this->data;
    }
}
