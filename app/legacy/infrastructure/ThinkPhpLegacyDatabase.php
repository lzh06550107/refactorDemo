<?php

declare(strict_types=1);

namespace app\legacy\infrastructure;

use app\legacy\contract\LegacyDatabase;
use InvalidArgumentException;
use think\facade\Db;

final readonly class ThinkPhpLegacyDatabase implements LegacyDatabase
{
    public function __construct(private string $connection = 'mysql', private string $prefix = 'ims_')
    {
        if (trim($connection) === '') {
            throw new InvalidArgumentException('Legacy database connection must not be empty.');
        }
        if (preg_match('/^[A-Za-z0-9_]*$/', $prefix) !== 1) {
            throw new InvalidArgumentException('Legacy database prefix contains unsupported characters.');
        }
    }

    public function fetchOne(string $table, array $where): ?array
    {
        $row = Db::connect($this->connection)
            ->table($this->table($table))
            ->where($where)
            ->find();
        return $row === null ? null : (array) $row;
    }

    public function fetchAll(string $table, array $where): array
    {
        $rows = Db::connect($this->connection)
            ->table($this->table($table))
            ->where($where)
            ->select();
        return method_exists($rows, 'toArray') ? $rows->toArray() : array_values((array) $rows);
    }

    private function table(string $table): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            throw new InvalidArgumentException('Legacy table name contains unsupported characters.');
        }
        return $this->prefix . $table;
    }
}
