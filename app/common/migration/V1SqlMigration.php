<?php

declare(strict_types=1);

namespace app\common\migration;

use think\migration\Migrator;

abstract class V1SqlMigration extends Migrator
{
    final protected function runBaseline(string $file): void
    {
        $loader = new V1BaselineSql(dirname(__DIR__, 3) . '/database/schema/v1');
        foreach ($loader->statements($file) as $statement) {
            $this->execute($statement);
        }
    }
}
