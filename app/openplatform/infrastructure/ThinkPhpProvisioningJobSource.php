<?php

declare(strict_types=1);

namespace app\openplatform\infrastructure;

use app\openplatform\contract\ProvisioningJobSource;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use think\facade\Db;

final readonly class ThinkPhpProvisioningJobSource implements ProvisioningJobSource
{
    public function dueProvisioningIds(DateTimeImmutable $now, int $limit): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('limit must be between 1 and 1000.');
        }

        $utcNow = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $sql = <<<SQL
SELECT provisioning_id
FROM authorizer_provisioning_jobs
WHERE (status = 'ready' AND next_attempt_at <= ?)
   OR (status = 'claimed' AND claim_expires_at IS NOT NULL AND claim_expires_at <= ?)
ORDER BY CASE WHEN status = 'claimed' THEN claim_expires_at ELSE next_attempt_at END ASC, provisioning_id ASC
LIMIT {$limit}
SQL;
        $rows = Db::query($sql, [$utcNow, $utcNow]);
        $ids = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['provisioning_id'] ?? null)) {
                continue;
            }
            $id = trim($row['provisioning_id']);
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        return $ids;
    }
}
