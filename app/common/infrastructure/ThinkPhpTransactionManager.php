<?php

declare(strict_types=1);

namespace app\common\infrastructure;

use app\common\contract\TransactionManager;
use think\facade\Db;

final class ThinkPhpTransactionManager implements TransactionManager
{
    public function run(callable $callback): mixed
    {
        return Db::transaction(static fn (): mixed => $callback());
    }
}
