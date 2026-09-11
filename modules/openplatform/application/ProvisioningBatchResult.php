<?php

declare(strict_types=1);

namespace modules\openplatform\application;

final readonly class ProvisioningBatchResult
{
    public function __construct(
        private int $discovered,
        private int $handled,
        private int $failed,
    ) {
    }

    public function discovered(): int
    {
        return $this->discovered;
    }

    public function handled(): int
    {
        return $this->handled;
    }

    public function failed(): int
    {
        return $this->failed;
    }
}
