<?php

declare(strict_types=1);

namespace app\openplatform\domain;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

final readonly class ProvisioningJob
{
    private function __construct(
        private string $provisioningId,
        private ProvisioningJobStatus $status,
        private DateTimeImmutable $nextAttemptAt,
        private ?string $claimHolderId,
        private ?DateTimeImmutable $claimExpiresAt,
        private int $attemptCount,
        private ?string $lastErrorCode,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
        if (trim($provisioningId) === '') {
            throw new InvalidArgumentException('provisioningId must not be empty.');
        }
        if ($attemptCount < 0) {
            throw new InvalidArgumentException('attemptCount must not be negative.');
        }
        if ($status === ProvisioningJobStatus::CLAIMED) {
            if ($claimHolderId === null || trim($claimHolderId) === '' || $claimExpiresAt === null) {
                throw new InvalidArgumentException('CLAIMED provisioning job requires holder and expiry.');
            }
        } elseif ($claimHolderId !== null || $claimExpiresAt !== null) {
            throw new InvalidArgumentException('Only CLAIMED provisioning job may retain lease ownership.');
        }
        if ($updatedAt < $createdAt) {
            throw new InvalidArgumentException('updatedAt must not precede createdAt.');
        }
    }

    public static function ready(string $provisioningId, DateTimeImmutable $nextAttemptAt, DateTimeImmutable $now): self
    {
        return new self(
            $provisioningId,
            ProvisioningJobStatus::READY,
            $nextAttemptAt,
            null,
            null,
            0,
            null,
            $now,
            $now,
        );
    }

    public static function reconstitute(
        string $provisioningId,
        ProvisioningJobStatus $status,
        DateTimeImmutable $nextAttemptAt,
        ?string $claimHolderId,
        ?DateTimeImmutable $claimExpiresAt,
        int $attemptCount,
        ?string $lastErrorCode,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $provisioningId,
            $status,
            $nextAttemptAt,
            $claimHolderId,
            $claimExpiresAt,
            $attemptCount,
            $lastErrorCode,
            $createdAt,
            $updatedAt,
        );
    }

    public function provisioningId(): string { return $this->provisioningId; }
    public function status(): ProvisioningJobStatus { return $this->status; }
    public function nextAttemptAt(): DateTimeImmutable { return $this->nextAttemptAt; }
    public function claimHolderId(): ?string { return $this->claimHolderId; }
    public function claimExpiresAt(): ?DateTimeImmutable { return $this->claimExpiresAt; }
    public function attemptCount(): int { return $this->attemptCount; }
    public function lastErrorCode(): ?string { return $this->lastErrorCode; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }

    public function claimableAt(DateTimeImmutable $now): bool
    {
        if ($this->status === ProvisioningJobStatus::READY) {
            return $this->nextAttemptAt <= $now;
        }
        if ($this->status === ProvisioningJobStatus::CLAIMED) {
            return $this->claimExpiresAt !== null && $this->claimExpiresAt <= $now;
        }
        return false;
    }

    public function claimedBy(string $holderId, DateTimeImmutable $now, int $ttlSeconds): self
    {
        if (!$this->claimableAt($now)) {
            throw new LogicException('Provisioning job is not claimable.');
        }
        $holderId = trim($holderId);
        if ($holderId === '' || $ttlSeconds < 1) {
            throw new InvalidArgumentException('Provisioning job claim requires holder and positive TTL.');
        }

        return new self(
            $this->provisioningId,
            ProvisioningJobStatus::CLAIMED,
            $this->nextAttemptAt,
            $holderId,
            $now->modify('+' . $ttlSeconds . ' seconds'),
            $this->attemptCount + 1,
            $this->lastErrorCode,
            $this->createdAt,
            $now,
        );
    }

    public function releasedBy(
        string $holderId,
        DateTimeImmutable $now,
        DateTimeImmutable $nextAttemptAt,
        ?string $errorCode,
    ): self {
        $this->assertLiveHolder($holderId, $now);
        return new self(
            $this->provisioningId,
            ProvisioningJobStatus::READY,
            $nextAttemptAt,
            null,
            null,
            $this->attemptCount,
            $this->nullableCode($errorCode),
            $this->createdAt,
            $now,
        );
    }

    public function completedBy(string $holderId, DateTimeImmutable $now): self
    {
        $this->assertLiveHolder($holderId, $now);
        return new self(
            $this->provisioningId,
            ProvisioningJobStatus::COMPLETED,
            $this->nextAttemptAt,
            null,
            null,
            $this->attemptCount,
            null,
            $this->createdAt,
            $now,
        );
    }

    public function deadBy(string $holderId, string $errorCode, DateTimeImmutable $now): self
    {
        $this->assertLiveHolder($holderId, $now);
        $errorCode = trim($errorCode);
        if ($errorCode === '') {
            throw new InvalidArgumentException('DEAD provisioning job requires a terminal error code.');
        }
        return new self(
            $this->provisioningId,
            ProvisioningJobStatus::DEAD,
            $this->nextAttemptAt,
            null,
            null,
            $this->attemptCount,
            $errorCode,
            $this->createdAt,
            $now,
        );
    }

    private function assertLiveHolder(string $holderId, DateTimeImmutable $now): void
    {
        if (
            $this->status !== ProvisioningJobStatus::CLAIMED
            || $this->claimHolderId === null
            || !hash_equals($this->claimHolderId, $holderId)
            || $this->claimExpiresAt === null
            || $this->claimExpiresAt <= $now
        ) {
            throw new LogicException('Provisioning job lease is not owned by the caller.');
        }
    }

    private function nullableCode(?string $errorCode): ?string
    {
        if ($errorCode === null) {
            return null;
        }
        $errorCode = trim($errorCode);
        return $errorCode === '' ? null : $errorCode;
    }
}
