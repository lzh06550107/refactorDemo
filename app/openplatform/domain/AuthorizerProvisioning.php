<?php

declare(strict_types=1);

namespace app\openplatform\domain;

use app\account\domain\AccountType;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

final readonly class AuthorizerProvisioning
{
    private function __construct(
        private string $id,
        private string $sourceIntentId,
        private string $tenantId,
        private string $componentPlatformId,
        private string $authorizerAppId,
        private ?AccountType $accountType,
        private AuthorizerProvisioningStatus $status,
        private ?int $metadataVersion,
        private ?string $quotaResourceKey,
        private ?string $quotaConsumeEntryId,
        private ?string $quotaReleaseEntryId,
        private ?string $accountId,
        private ?string $lastErrorCode,
        private ?string $lastErrorStage,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
        private ?DateTimeImmutable $completedAt,
        private int $version,
    ) {
        foreach ([$id, $sourceIntentId, $tenantId, $componentPlatformId, $authorizerAppId] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Authorizer provisioning identifiers must not be empty.');
            }
        }
        if ($metadataVersion !== null && $metadataVersion < 1) {
            throw new InvalidArgumentException('metadataVersion must be positive when present.');
        }
        if ($version < 1) {
            throw new InvalidArgumentException('Authorizer provisioning version must be positive.');
        }
        if ($updatedAt < $createdAt) {
            throw new InvalidArgumentException('updatedAt must not precede createdAt.');
        }
        if ($completedAt !== null && $completedAt < $createdAt) {
            throw new InvalidArgumentException('completedAt must not precede createdAt.');
        }
    }

    public static function pending(
        string $id,
        string $sourceIntentId,
        string $tenantId,
        string $componentPlatformId,
        string $authorizerAppId,
        DateTimeImmutable $now,
    ): self {
        return new self(
            $id,
            $sourceIntentId,
            $tenantId,
            $componentPlatformId,
            $authorizerAppId,
            null,
            AuthorizerProvisioningStatus::PENDING_METADATA,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $now,
            $now,
            null,
            1,
        );
    }

    public static function reconstitute(
        string $id,
        string $sourceIntentId,
        string $tenantId,
        string $componentPlatformId,
        string $authorizerAppId,
        ?AccountType $accountType,
        AuthorizerProvisioningStatus $status,
        ?int $metadataVersion,
        ?string $quotaResourceKey,
        ?string $quotaConsumeEntryId,
        ?string $quotaReleaseEntryId,
        ?string $accountId,
        ?string $lastErrorCode,
        ?string $lastErrorStage,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        ?DateTimeImmutable $completedAt,
        int $version,
    ): self {
        return new self(
            $id,
            $sourceIntentId,
            $tenantId,
            $componentPlatformId,
            $authorizerAppId,
            $accountType,
            $status,
            $metadataVersion,
            $quotaResourceKey,
            $quotaConsumeEntryId,
            $quotaReleaseEntryId,
            $accountId,
            $lastErrorCode,
            $lastErrorStage,
            $createdAt,
            $updatedAt,
            $completedAt,
            $version,
        );
    }

    public function id(): string { return $this->id; }
    public function sourceIntentId(): string { return $this->sourceIntentId; }
    public function tenantId(): string { return $this->tenantId; }
    public function componentPlatformId(): string { return $this->componentPlatformId; }
    public function authorizerAppId(): string { return $this->authorizerAppId; }
    public function accountType(): ?AccountType { return $this->accountType; }
    public function status(): AuthorizerProvisioningStatus { return $this->status; }
    public function metadataVersion(): ?int { return $this->metadataVersion; }
    public function quotaResourceKey(): ?string { return $this->quotaResourceKey; }
    public function quotaConsumeEntryId(): ?string { return $this->quotaConsumeEntryId; }
    public function quotaReleaseEntryId(): ?string { return $this->quotaReleaseEntryId; }
    public function accountId(): ?string { return $this->accountId; }
    public function lastErrorCode(): ?string { return $this->lastErrorCode; }
    public function lastErrorStage(): ?string { return $this->lastErrorStage; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }
    public function completedAt(): ?DateTimeImmutable { return $this->completedAt; }
    public function version(): int { return $this->version; }

    public function withMetadata(AccountType $accountType, int $metadataVersion, DateTimeImmutable $now): self
    {
        if (!in_array($this->status, [
            AuthorizerProvisioningStatus::PENDING_METADATA,
            AuthorizerProvisioningStatus::METADATA_FAILED,
            AuthorizerProvisioningStatus::METADATA_READY,
        ], true)) {
            $this->invalidTransition('metadata');
        }
        if ($metadataVersion < 1) {
            throw new InvalidArgumentException('metadataVersion must be positive.');
        }

        if ($this->accountType !== null && $this->accountType !== $accountType) {
            return $this->next(
                AuthorizerProvisioningStatus::METADATA_TYPE_CONFLICT,
                $this->accountType,
                $metadataVersion,
                $this->quotaResourceKey,
                $this->quotaConsumeEntryId,
                $this->quotaReleaseEntryId,
                $this->accountId,
                'metadata_type_conflict',
                'metadata',
                $now,
                $now,
            );
        }

        return $this->next(
            AuthorizerProvisioningStatus::METADATA_READY,
            $this->accountType ?? $accountType,
            $metadataVersion,
            $this->quotaResourceKey,
            $this->quotaConsumeEntryId,
            $this->quotaReleaseEntryId,
            $this->accountId,
            null,
            null,
            $now,
            null,
        );
    }

    public function metadataFailed(string $errorCode, DateTimeImmutable $now): self
    {
        if (!in_array($this->status, [
            AuthorizerProvisioningStatus::PENDING_METADATA,
            AuthorizerProvisioningStatus::METADATA_FAILED,
        ], true)) {
            $this->invalidTransition('metadata failure');
        }

        return $this->next(
            AuthorizerProvisioningStatus::METADATA_FAILED,
            $this->accountType,
            $this->metadataVersion,
            $this->quotaResourceKey,
            $this->quotaConsumeEntryId,
            $this->quotaReleaseEntryId,
            $this->accountId,
            $this->requiredCode($errorCode),
            'metadata',
            $now,
            null,
        );
    }

    public function withQuotaConsumed(string $resourceKey, string $entryId, DateTimeImmutable $now): self
    {
        if ($this->status !== AuthorizerProvisioningStatus::METADATA_READY || $this->accountType === null) {
            $this->invalidTransition('quota consumed');
        }
        if (trim($resourceKey) === '' || trim($entryId) === '') {
            throw new InvalidArgumentException('Quota resource and consume entry must not be empty.');
        }

        return $this->next(
            AuthorizerProvisioningStatus::QUOTA_CONSUMED,
            $this->accountType,
            $this->metadataVersion,
            $resourceKey,
            $entryId,
            $this->quotaReleaseEntryId,
            $this->accountId,
            null,
            null,
            $now,
            null,
        );
    }

    public function provisioned(string $accountId, DateTimeImmutable $now): self
    {
        if (!in_array($this->status, [
            AuthorizerProvisioningStatus::QUOTA_CONSUMED,
            AuthorizerProvisioningStatus::PROVISION_FAILED,
        ], true)) {
            $this->invalidTransition('provisioned');
        }
        if (trim($accountId) === '') {
            throw new InvalidArgumentException('Provisioned Account id must not be empty.');
        }

        return $this->next(
            AuthorizerProvisioningStatus::PROVISIONED,
            $this->accountType,
            $this->metadataVersion,
            $this->quotaResourceKey,
            $this->quotaConsumeEntryId,
            $this->quotaReleaseEntryId,
            $accountId,
            null,
            null,
            $now,
            $now,
        );
    }

    public function reconnected(string $accountId, DateTimeImmutable $now): self
    {
        if ($this->status !== AuthorizerProvisioningStatus::METADATA_READY) {
            $this->invalidTransition('reconnected');
        }
        if (trim($accountId) === '') {
            throw new InvalidArgumentException('Reconnected Account id must not be empty.');
        }

        return $this->next(
            AuthorizerProvisioningStatus::RECONNECTED,
            $this->accountType,
            $this->metadataVersion,
            $this->quotaResourceKey,
            $this->quotaConsumeEntryId,
            $this->quotaReleaseEntryId,
            $accountId,
            null,
            null,
            $now,
            $now,
        );
    }

    public function quotaBlocked(string $errorCode, DateTimeImmutable $now): self
    {
        if ($this->status !== AuthorizerProvisioningStatus::METADATA_READY) {
            $this->invalidTransition('quota blocked');
        }

        return $this->next(
            AuthorizerProvisioningStatus::QUOTA_BLOCKED,
            $this->accountType,
            $this->metadataVersion,
            $this->quotaResourceKey,
            $this->quotaConsumeEntryId,
            $this->quotaReleaseEntryId,
            $this->accountId,
            $this->requiredCode($errorCode),
            'quota',
            $now,
            $now,
        );
    }

    public function bindingConflict(string $errorCode, DateTimeImmutable $now): self
    {
        if ($this->status !== AuthorizerProvisioningStatus::METADATA_READY) {
            $this->invalidTransition('binding conflict');
        }

        return $this->next(
            AuthorizerProvisioningStatus::BINDING_CONFLICT,
            $this->accountType,
            $this->metadataVersion,
            $this->quotaResourceKey,
            $this->quotaConsumeEntryId,
            $this->quotaReleaseEntryId,
            $this->accountId,
            $this->requiredCode($errorCode),
            'ownership',
            $now,
            $now,
        );
    }

    public function provisionFailed(string $errorCode, DateTimeImmutable $now): self
    {
        if (!in_array($this->status, [
            AuthorizerProvisioningStatus::QUOTA_CONSUMED,
            AuthorizerProvisioningStatus::PROVISION_FAILED,
        ], true)) {
            $this->invalidTransition('provision failure');
        }

        return $this->next(
            AuthorizerProvisioningStatus::PROVISION_FAILED,
            $this->accountType,
            $this->metadataVersion,
            $this->quotaResourceKey,
            $this->quotaConsumeEntryId,
            $this->quotaReleaseEntryId,
            $this->accountId,
            $this->requiredCode($errorCode),
            'finalization',
            $now,
            null,
        );
    }

    public function authorizationInactive(DateTimeImmutable $now): self
    {
        if (!in_array($this->status, [
            AuthorizerProvisioningStatus::PENDING_METADATA,
            AuthorizerProvisioningStatus::METADATA_FAILED,
            AuthorizerProvisioningStatus::METADATA_READY,
            AuthorizerProvisioningStatus::QUOTA_CONSUMED,
            AuthorizerProvisioningStatus::PROVISION_FAILED,
        ], true)) {
            $this->invalidTransition('authorization inactive');
        }

        return $this->next(
            AuthorizerProvisioningStatus::AUTHORIZATION_INACTIVE,
            $this->accountType,
            $this->metadataVersion,
            $this->quotaResourceKey,
            $this->quotaConsumeEntryId,
            $this->quotaReleaseEntryId,
            $this->accountId,
            'authorization_inactive',
            'authorization',
            $now,
            $now,
        );
    }

    public function withQuotaRelease(string $entryId, DateTimeImmutable $now): self
    {
        if (trim($entryId) === '' || $this->quotaConsumeEntryId === null) {
            throw new LogicException('Quota release requires a prior quota consume entry.');
        }

        return $this->next(
            $this->status,
            $this->accountType,
            $this->metadataVersion,
            $this->quotaResourceKey,
            $this->quotaConsumeEntryId,
            $entryId,
            $this->accountId,
            $this->lastErrorCode,
            $this->lastErrorStage,
            $now,
            $this->completedAt,
        );
    }

    private function next(
        AuthorizerProvisioningStatus $status,
        ?AccountType $accountType,
        ?int $metadataVersion,
        ?string $quotaResourceKey,
        ?string $quotaConsumeEntryId,
        ?string $quotaReleaseEntryId,
        ?string $accountId,
        ?string $lastErrorCode,
        ?string $lastErrorStage,
        DateTimeImmutable $updatedAt,
        ?DateTimeImmutable $completedAt,
    ): self {
        return new self(
            $this->id,
            $this->sourceIntentId,
            $this->tenantId,
            $this->componentPlatformId,
            $this->authorizerAppId,
            $accountType,
            $status,
            $metadataVersion,
            $quotaResourceKey,
            $quotaConsumeEntryId,
            $quotaReleaseEntryId,
            $accountId,
            $lastErrorCode,
            $lastErrorStage,
            $this->createdAt,
            $updatedAt,
            $completedAt,
            $this->version + 1,
        );
    }

    private function requiredCode(string $errorCode): string
    {
        $errorCode = trim($errorCode);
        if ($errorCode === '') {
            throw new InvalidArgumentException('Provisioning error code must not be empty.');
        }
        return $errorCode;
    }

    private function invalidTransition(string $target): never
    {
        throw new LogicException(sprintf('Cannot transition authorizer provisioning from %s to %s.', $this->status->value, $target));
    }
}
