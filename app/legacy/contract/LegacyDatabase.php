<?php

declare(strict_types=1);

namespace app\legacy\contract;

interface LegacyDatabase
{
    public function fetchOne(string $table, array $where): ?array;

    /** @return list<array<string, mixed>> */
    public function fetchAll(string $table, array $where): array;
}
