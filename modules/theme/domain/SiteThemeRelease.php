<?php

declare(strict_types=1);

namespace modules\theme\domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class SiteThemeRelease
{
    public function __construct(
        private string $id,
        private string $tenantId,
        private string $siteId,
        private string $themeVersionId,
        private string $styleSnapshotId,
        private ?string $previousReleaseId,
        private ?string $rollbackOfReleaseId,
        private string $idempotencyKey,
        private DateTimeImmutable $createdAt,
    ) {
        foreach (['id' => $id, 'tenantId' => $tenantId, 'siteId' => $siteId, 'themeVersionId' => $themeVersionId, 'styleSnapshotId' => $styleSnapshotId, 'idempotencyKey' => $idempotencyKey] as $key => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($key . ' must not be empty.');
            }
        }
    }
    public function id(): string { return $this->id; }
    public function tenantId(): string { return $this->tenantId; }
    public function siteId(): string { return $this->siteId; }
    public function themeVersionId(): string { return $this->themeVersionId; }
    public function styleSnapshotId(): string { return $this->styleSnapshotId; }
    public function previousReleaseId(): ?string { return $this->previousReleaseId; }
    public function rollbackOfReleaseId(): ?string { return $this->rollbackOfReleaseId; }
    public function idempotencyKey(): string { return $this->idempotencyKey; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
}
