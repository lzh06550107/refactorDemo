<?php

declare(strict_types=1);

use app\openplatform\application\ProvisioningBatchRunner;
use app\openplatform\contract\ProvisioningJobSource;

final class BatchRunnerSourceFake implements ProvisioningJobSource
{
    public int $calls = 0;

    /** @param list<string> $ids */
    public function __construct(private array $ids)
    {
    }

    public function dueProvisioningIds(DateTimeImmutable $now, int $limit): array
    {
        $this->calls++;
        return array_slice($this->ids, 0, $limit);
    }
}

$now = new DateTimeImmutable('2026-09-10T09:30:00Z', new DateTimeZone('UTC'));
$source = new BatchRunnerSourceFake(['p-1', 'p-2', 'p-3']);
$seen = [];
$executor = static function (string $provisioningId, DateTimeImmutable $now) use (&$seen): void {
    $seen[] = $provisioningId;
    if ($provisioningId === 'p-2') {
        throw new RuntimeException('SECRET_ACCESS_TOKEN_123 must never escape the runner');
    }
};
$result = (new ProvisioningBatchRunner($source, $executor))->runBatch($now, 100);

expectSame(3, $result->discovered(), 'batch discovers all due ids');
expectSame(2, $result->handled(), 'normal returns are counted as handled, including safe claim-loss no-ops');
expectSame(1, $result->failed(), 'batch reports isolated failure count');
expectSame(['p-1', 'p-2', 'p-3'], $seen, 'runner continues after one job throws');

expectThrows(
    static fn() => (new ProvisioningBatchRunner($source, $executor))->runBatch($now, 0),
    InvalidArgumentException::class,
    'limit below one is rejected',
);
expectThrows(
    static fn() => (new ProvisioningBatchRunner($source, $executor))->runBatch($now, 1001),
    InvalidArgumentException::class,
    'limit above one thousand is rejected',
);
expectSame(1, $source->calls, 'invalid limits fail before source access');
