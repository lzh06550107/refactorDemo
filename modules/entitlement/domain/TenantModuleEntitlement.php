<?php

declare(strict_types=1);

namespace modules\entitlement\domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class TenantModuleEntitlement
{
    public function __construct(
        private string $id,
        private string $tenantId,
        private string $moduleId,
        private ModuleEntitlementSource $source,
        private ModuleEntitlementStatus $status,
        private ?DateTimeImmutable $startsAt,
        private ?DateTimeImmutable $endsAt,
    ) {
        foreach (['id' => $id, 'tenantId' => $tenantId, 'moduleId' => $moduleId] as $name => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($name . ' must not be empty.');
            }
        }
        if ($startsAt !== null && $endsAt !== null && $endsAt < $startsAt) {
            throw new InvalidArgumentException('Entitlement end time must not precede start time.');
        }
    }

    public function id(): string { return $this->id; }
    public function tenantId(): string { return $this->tenantId; }
    public function moduleId(): string { return $this->moduleId; }
    public function source(): ModuleEntitlementSource { return $this->source; }
    public function status(): ModuleEntitlementStatus { return $this->status; }
    public function startsAt(): ?DateTimeImmutable { return $this->startsAt; }
    public function endsAt(): ?DateTimeImmutable { return $this->endsAt; }

    public function isActiveAt(DateTimeImmutable $at): bool
    {
        if ($this->status !== ModuleEntitlementStatus::ACTIVE) {
            return false;
        }
        if ($this->startsAt !== null && $at < $this->startsAt) {
            return false;
        }
        return $this->endsAt === null || $at <= $this->endsAt;
    }
}
