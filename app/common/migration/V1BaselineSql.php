<?php

declare(strict_types=1);

namespace app\common\migration;

use RuntimeException;

final class V1BaselineSql
{
    public function __construct(private readonly string $directory)
    {
    }

    /** @return list<string> */
    public function statements(string $file): array
    {
        if (basename($file) !== $file || preg_match('/^[A-Za-z0-9_]+\.sql$/', $file) !== 1) {
            throw new RuntimeException('Invalid V1 baseline filename.');
        }

        $sql = @file_get_contents($this->directory . DIRECTORY_SEPARATOR . $file);
        if (!is_string($sql) || trim($sql) === '') {
            throw new RuntimeException('V1 baseline SQL is missing or empty: ' . $file);
        }

        $parts = preg_split('/;\s*(?:\R|$)/', trim($sql));
        $statements = array_values(array_filter(
            array_map(
                static fn (string $statement): string => trim($statement),
                is_array($parts) ? $parts : [],
            ),
            static fn (string $statement): bool => $statement !== '',
        ));

        if ($statements === []) {
            throw new RuntimeException('V1 baseline SQL has no executable statements: ' . $file);
        }

        return $statements;
    }
}
