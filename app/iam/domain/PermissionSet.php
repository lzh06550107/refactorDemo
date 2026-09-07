<?php

declare(strict_types=1);

namespace app\iam\domain;

final readonly class PermissionSet
{
    /** @var array<string, true> */
    private array $keys;

    /** @param list<string> $keys */
    public function __construct(array $keys = [], private bool $all = false)
    {
        $normalized = [];
        foreach ($keys as $key) {
            $permission = new Permission($key);
            $normalized[$permission->key()] = true;
        }
        $this->keys = $normalized;
    }

    public static function all(): self
    {
        return new self([], true);
    }

    public function isAll(): bool
    {
        return $this->all;
    }

    public function contains(Permission $permission): bool
    {
        return $this->all || isset($this->keys[$permission->key()]);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->keys);
    }
}
