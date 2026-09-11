<?php

declare(strict_types=1);

namespace modules\module\domain;

use modules\account\domain\AccountType;

final readonly class ModuleSupportMatrix
{
    /** @var array<string, true> */
    private array $supported;

    /** @param list<AccountType> $supported */
    public function __construct(array $supported)
    {
        $map = [];
        foreach ($supported as $type) {
            $map[$type->value] = true;
        }
        $this->supported = $map;
    }

    public function supports(AccountType $type): bool
    {
        return isset($this->supported[$type->value]);
    }

    /** @return list<string> */
    public function values(): array
    {
        return array_keys($this->supported);
    }
}
