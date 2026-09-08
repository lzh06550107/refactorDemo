<?php

declare(strict_types=1);

namespace app\openplatform\domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AuthorizationIntent
{
    private function __construct(
        private string $id,
        private string $componentPlatformId,
        private string $tenantId,
        private string $targetAccountId,
        private string $stateHash,
        private string $preAuthCodeHash,
        private string $requestedAuthType,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $expiresAt,
        private DateTimeImmutable $providerPreAuthExpiresAt,
        private ?string $claimHolderId,
        private ?DateTimeImmutable $claimExpiresAt,
        private ?DateTimeImmutable $completedAt,
        private ?string $completedAuthorizerAppId,
        private int $version,
    ) {
        foreach ([
            'id' => $id,
            'componentPlatformId' => $componentPlatformId,
            'tenantId' => $tenantId,
            'targetAccountId' => $targetAccountId,
            'requestedAuthType' => $requestedAuthType,
        ] as $name => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($name . ' must not be empty.');
            }
        }
        foreach (['stateHash' => $stateHash, 'preAuthCodeHash' => $preAuthCodeHash] as $name => $hash) {
            if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
                throw new InvalidArgumentException($name . ' must be a lowercase SHA-256 hex digest.');
            }
        }
        if ($expiresAt <= $createdAt || $providerPreAuthExpiresAt <= $createdAt) {
            throw new InvalidArgumentException('Authorization intent expiry must be after creation.');
        }
        if ($version < 1) {
            throw new InvalidArgumentException('Authorization intent version must be positive.');
        }
        if (($claimHolderId === null) !== ($claimExpiresAt === null)) {
            throw new InvalidArgumentException('Authorization intent claim holder and expiry must be set together.');
        }
        if (($completedAt === null) !== ($completedAuthorizerAppId === null)) {
            throw new InvalidArgumentException('Authorization intent completion time and authorizer AppId must be set together.');
        }
    }

    public static function reconstitute(
        string $id,
        string $componentPlatformId,
        string $tenantId,
        string $targetAccountId,
        string $stateHash,
        string $preAuthCodeHash,
        string $requestedAuthType,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $providerPreAuthExpiresAt,
        ?string $claimHolderId,
        ?DateTimeImmutable $claimExpiresAt,
        ?DateTimeImmutable $completedAt,
        ?string $completedAuthorizerAppId,
        int $version,
    ): self {
        return new self(
            $id,
            $componentPlatformId,
            $tenantId,
            $targetAccountId,
            $stateHash,
            $preAuthCodeHash,
            $requestedAuthType,
            $createdAt,
            $expiresAt,
            $providerPreAuthExpiresAt,
            $claimHolderId,
            $claimExpiresAt,
            $completedAt,
            $completedAuthorizerAppId,
            $version,
        );
    }

    public static function pending(
        string $id,
        string $componentPlatformId,
        string $tenantId,
        string $targetAccountId,
        string $stateHash,
        string $preAuthCodeHash,
        string $requestedAuthType,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $providerPreAuthExpiresAt,
    ): self {
        return new self(
            $id,
            $componentPlatformId,
            $tenantId,
            $targetAccountId,
            $stateHash,
            $preAuthCodeHash,
            $requestedAuthType,
            $createdAt,
            $expiresAt,
            $providerPreAuthExpiresAt,
            null,
            null,
            null,
            null,
            1,
        );
    }

    public function id(): string { return $this->id; }
    public function componentPlatformId(): string { return $this->componentPlatformId; }
    public function tenantId(): string { return $this->tenantId; }
    public function targetAccountId(): string { return $this->targetAccountId; }
    public function stateHash(): string { return $this->stateHash; }
    public function preAuthCodeHash(): string { return $this->preAuthCodeHash; }
    public function requestedAuthType(): string { return $this->requestedAuthType; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function expiresAt(): DateTimeImmutable { return $this->expiresAt; }
    public function providerPreAuthExpiresAt(): DateTimeImmutable { return $this->providerPreAuthExpiresAt; }
    public function claimHolderId(): ?string { return $this->claimHolderId; }
    public function claimExpiresAt(): ?DateTimeImmutable { return $this->claimExpiresAt; }
    public function completedAt(): ?DateTimeImmutable { return $this->completedAt; }
    public function completedAuthorizerAppId(): ?string { return $this->completedAuthorizerAppId; }
    public function version(): int { return $this->version; }

    public function effectiveExpiresAt(): DateTimeImmutable
    {
        return $this->providerPreAuthExpiresAt < $this->expiresAt ? $this->providerPreAuthExpiresAt : $this->expiresAt;
    }

    public function validAt(DateTimeImmutable $now): bool
    {
        return !$this->completed() && $now < $this->effectiveExpiresAt();
    }

    public function claimableAt(DateTimeImmutable $now): bool
    {
        if (!$this->validAt($now)) {
            return false;
        }
        return $this->claimHolderId === null || $this->claimExpiresAt === null || $this->claimExpiresAt <= $now;
    }

    public function completed(): bool
    {
        return $this->completedAt !== null;
    }

    public function withClaim(string $holderId, DateTimeImmutable $claimExpiresAt): self
    {
        if (trim($holderId) === '') {
            throw new InvalidArgumentException('Authorization intent claim holder must not be empty.');
        }
        if ($claimExpiresAt <= $this->createdAt) {
            throw new InvalidArgumentException('Authorization intent claim expiry must be after creation.');
        }
        return new self(
            $this->id,
            $this->componentPlatformId,
            $this->tenantId,
            $this->targetAccountId,
            $this->stateHash,
            $this->preAuthCodeHash,
            $this->requestedAuthType,
            $this->createdAt,
            $this->expiresAt,
            $this->providerPreAuthExpiresAt,
            $holderId,
            $claimExpiresAt,
            $this->completedAt,
            $this->completedAuthorizerAppId,
            $this->version + 1,
        );
    }

    public function completedBy(string $authorizerAppId, DateTimeImmutable $completedAt): self
    {
        if (trim($authorizerAppId) === '') {
            throw new InvalidArgumentException('Completed authorizer AppId must not be empty.');
        }
        return new self(
            $this->id,
            $this->componentPlatformId,
            $this->tenantId,
            $this->targetAccountId,
            $this->stateHash,
            $this->preAuthCodeHash,
            $this->requestedAuthType,
            $this->createdAt,
            $this->expiresAt,
            $this->providerPreAuthExpiresAt,
            null,
            null,
            $completedAt,
            $authorizerAppId,
            $this->version + 1,
        );
    }
}
