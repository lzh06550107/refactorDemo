<?php

declare(strict_types=1);

namespace modules\iam\contract;

interface AdminIdGenerator
{
    public function generate(): string;
}
