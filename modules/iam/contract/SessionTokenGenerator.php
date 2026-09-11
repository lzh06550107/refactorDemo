<?php

declare(strict_types=1);

namespace modules\iam\contract;

interface SessionTokenGenerator
{
    public function generate(): string;
}
