<?php

declare(strict_types=1);

namespace app\common\contract;

interface TransactionManager
{
    public function run(callable $callback): mixed;
}
