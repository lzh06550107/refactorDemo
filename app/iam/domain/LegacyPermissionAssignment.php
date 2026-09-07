<?php

declare(strict_types=1);

namespace app\iam\domain;

final readonly class LegacyPermissionAssignment
{
    private const ROLE_DEFAULT = 'role_default';
    private const EXPLICIT = 'explicit';

    private function __construct(
        private string $mode,
        private PermissionSet $permissions,
    ) {
    }

    public static function roleDefault(): self
    {
        return new self(self::ROLE_DEFAULT, new PermissionSet());
    }

    public static function all(): self
    {
        return new self(self::EXPLICIT, PermissionSet::all());
    }

    /** @param list<string> $keys */
    public static function explicit(array $keys): self
    {
        return new self(self::EXPLICIT, new PermissionSet($keys));
    }

    public function usesRoleDefault(): bool
    {
        return $this->mode === self::ROLE_DEFAULT;
    }

    public function permissions(): PermissionSet
    {
        return $this->permissions;
    }
}
