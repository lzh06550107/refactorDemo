<?php

declare(strict_types=1);

namespace modules\openplatform\infrastructure;

use modules\openplatform\contract\ProvisioningJobScheduler;
use DateTimeImmutable;
use think\facade\Db;

final readonly class ThinkPhpProvisioningJobScheduler implements ProvisioningJobScheduler
{
    public function requeue(string $provisioningId, DateTimeImmutable $now): bool
    {
        return Db::transaction(function () use ($provisioningId, $now): bool {
            $row = Db::table('authorizer_provisioning_jobs')
                ->where('provisioning_id', $provisioningId)
                ->lock(true)
                ->find();
            if (!is_array($row)) {
                return false;
            }

            $status = (string) ($row['status'] ?? '');
            if ($status === 'claimed') {
                return false;
            }
            if (!in_array($status, ['ready', 'completed', 'dead'], true)) {
                return false;
            }

            return Db::table('authorizer_provisioning_jobs')
                ->where('provisioning_id', $provisioningId)
                ->update([
                    'status' => 'ready',
                    'next_attempt_at' => $now->format('Y-m-d H:i:s.u'),
                    'claim_holder_id' => null,
                    'claim_expires_at' => null,
                    'attempt_count' => 0,
                    'last_error_code' => null,
                    'updated_at' => $now->format('Y-m-d H:i:s.u'),
                ]) >= 0;
        });
    }
}
