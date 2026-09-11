<?php

declare(strict_types=1);

namespace modules\iam\contract;

interface SessionIdGenerator
{
    public function generate(): string;
}
