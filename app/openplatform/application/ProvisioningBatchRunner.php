<?php

declare(strict_types=1);

namespace app\openplatform\application;

use app\openplatform\contract\ProvisioningJobSource;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

final readonly class ProvisioningBatchRunner
{
    private Closure $executor;

    public function __construct(
        private ProvisioningJobSource $source,
        callable $executor,
    ) {
        $this->executor = Closure::fromCallable($executor);
    }

    public function runBatch(DateTimeImmutable $now, int $limit): ProvisioningBatchResult
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('limit must be between 1 and 1000.');
        }

        $ids = $this->source->dueProvisioningIds($now, $limit);
        $handled = 0;
        $failed = 0;

        foreach ($ids as $provisioningId) {
            try {
                ($this->executor)($provisioningId, $now);
                $handled++;
            } catch (Throwable) {
                $failed++;
            }
        }

        return new ProvisioningBatchResult(count($ids), $handled, $failed);
    }
}
