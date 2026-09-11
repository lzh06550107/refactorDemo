<?php

declare(strict_types=1);

namespace modules\openplatform\infrastructure;

use modules\openplatform\contract\ProvisioningJobRepository;
use modules\openplatform\domain\ProvisioningJob;
use modules\openplatform\domain\ProvisioningJobStatus;
use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;

final readonly class ThinkPhpProvisioningJobRepository implements ProvisioningJobRepository
{
    public function insert(ProvisioningJob $job): void
    {
        Db::table('authorizer_provisioning_jobs')->insert($this->row($job));
    }

    public function tryClaim(
        string $provisioningId,
        string $holderId,
        DateTimeImmutable $now,
        int $ttlSeconds,
    ): ?ProvisioningJob {
        return Db::transaction(function () use ($provisioningId, $holderId, $now, $ttlSeconds): ?ProvisioningJob {
            $row = Db::table('authorizer_provisioning_jobs')->where('provisioning_id', $provisioningId)->lock(true)->find();
            if (!is_array($row)) {
                return null;
            }

            $status = (string) ($row['status'] ?? '');
            if (!in_array($status, ['ready', 'claimed'], true)) {
                return null;
            }
            if ($status === 'ready' && $this->date((string) $row['next_attempt_at']) > $now) {
                return null;
            }
            if (
                $status === 'claimed'
                && (empty($row['claim_expires_at']) || $this->date((string) $row['claim_expires_at']) > $now)
            ) {
                return null;
            }

            $current = $this->map($row);
            if (!$current->claimableAt($now)) {
                return null;
            }
            $claimed = $current->claimedBy($holderId, $now, $ttlSeconds);
            Db::table('authorizer_provisioning_jobs')->where('provisioning_id', $provisioningId)->update($this->mutableRow($claimed));
            return $claimed;
        });
    }

    public function release(
        string $provisioningId,
        string $holderId,
        DateTimeImmutable $nextAttemptAt,
        ?string $errorCode,
    ): bool {
        return Db::transaction(function () use ($provisioningId, $holderId, $nextAttemptAt, $errorCode): bool {
            $row = Db::table('authorizer_provisioning_jobs')->where('provisioning_id', $provisioningId)->lock(true)->find();
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $current = $this->ownedLiveClaim($row, $holderId, $now);
            if ($current === null) {
                return false;
            }

            $released = $current->releasedBy($holderId, $now, $nextAttemptAt, $errorCode);
            return Db::table('authorizer_provisioning_jobs')->where('provisioning_id', $provisioningId)->update($this->mutableRow($released)) === 1;
        });
    }

    public function complete(string $provisioningId, string $holderId): bool
    {
        return Db::transaction(function () use ($provisioningId, $holderId): bool {
            $row = Db::table('authorizer_provisioning_jobs')->where('provisioning_id', $provisioningId)->lock(true)->find();
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $current = $this->ownedLiveClaim($row, $holderId, $now);
            if ($current === null) {
                return false;
            }

            $completed = $current->completedBy($holderId, $now);
            return Db::table('authorizer_provisioning_jobs')->where('provisioning_id', $provisioningId)->update($this->mutableRow($completed)) === 1;
        });
    }

    public function dead(string $provisioningId, string $holderId, string $errorCode): bool
    {
        return Db::transaction(function () use ($provisioningId, $holderId, $errorCode): bool {
            $row = Db::table('authorizer_provisioning_jobs')->where('provisioning_id', $provisioningId)->lock(true)->find();
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $current = $this->ownedLiveClaim($row, $holderId, $now);
            if ($current === null) {
                return false;
            }

            $dead = $current->deadBy($holderId, $errorCode, $now);
            return Db::table('authorizer_provisioning_jobs')->where('provisioning_id', $provisioningId)->update($this->mutableRow($dead)) === 1;
        });
    }

    private function ownedLiveClaim(mixed $row, string $holderId, DateTimeImmutable $now): ?ProvisioningJob
    {
        if (
            !is_array($row)
            || (string) ($row['status'] ?? '') !== 'claimed'
            || !is_string($row['claim_holder_id'] ?? null)
            || !hash_equals((string) $row['claim_holder_id'], $holderId)
            || empty($row['claim_expires_at'])
            || $this->date((string) $row['claim_expires_at']) <= $now
        ) {
            return null;
        }
        return $this->map($row);
    }

    /** @return array<string,mixed> */
    private function row(ProvisioningJob $job): array
    {
        return ['provisioning_id' => $job->provisioningId()] + $this->mutableRow($job) + [
            'created_at' => $this->sqlDate($job->createdAt()),
        ];
    }

    /** @return array<string,mixed> */
    private function mutableRow(ProvisioningJob $job): array
    {
        return [
            'status' => $job->status()->value,
            'next_attempt_at' => $this->sqlDate($job->nextAttemptAt()),
            'claim_holder_id' => $job->claimHolderId(),
            'claim_expires_at' => $job->claimExpiresAt() === null ? null : $this->sqlDate($job->claimExpiresAt()),
            'attempt_count' => $job->attemptCount(),
            'last_error_code' => $job->lastErrorCode(),
            'updated_at' => $this->sqlDate($job->updatedAt()),
        ];
    }

    private function map(array $row): ProvisioningJob
    {
        return ProvisioningJob::reconstitute(
            (string) $row['provisioning_id'],
            ProvisioningJobStatus::from((string) $row['status']),
            $this->date((string) $row['next_attempt_at']),
            $this->nullableString($row['claim_holder_id'] ?? null),
            empty($row['claim_expires_at']) ? null : $this->date((string) $row['claim_expires_at']),
            (int) $row['attempt_count'],
            $this->nullableString($row['last_error_code'] ?? null),
            $this->date((string) $row['created_at']),
            $this->date((string) $row['updated_at']),
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function sqlDate(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.u');
    }
}
