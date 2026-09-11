<?php

declare(strict_types=1);

namespace modules\oauth\domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class OAuthState
{
    public function __construct(
        private string $id,
        private string $nonceHash,
        private string $tenantId,
        private string $businessAccountId,
        private string $oauthProviderAccountId,
        private string $providerType,
        private string $returnUrl,
        private DateTimeImmutable $issuedAt,
        private DateTimeImmutable $expiresAt,
        private ?DateTimeImmutable $consumedAt = null,
        private ?string $resultMemberId = null,
        private ?string $resultExternalIdentityId = null,
    ) {
        foreach ([$id, $tenantId, $businessAccountId, $oauthProviderAccountId, $providerType, $returnUrl] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('OAuth state identifiers and return URL must not be empty.');
            }
        }
        if (!preg_match('/^[a-f0-9]{64}$/i', $nonceHash)) {
            throw new InvalidArgumentException('OAuth state nonce hash must be a SHA-256 hex digest.');
        }
        if ($expiresAt <= $issuedAt) {
            throw new InvalidArgumentException('OAuth state expiry must be after issue time.');
        }
        if (($resultMemberId === null) !== ($resultExternalIdentityId === null)) {
            throw new InvalidArgumentException('OAuth state semantic result must be complete.');
        }
        if ($consumedAt === null && $resultMemberId !== null) {
            throw new InvalidArgumentException('OAuth state result requires consumption timestamp.');
        }
    }

    public function id(): string { return $this->id; }
    public function nonceHash(): string { return $this->nonceHash; }
    public function tenantId(): string { return $this->tenantId; }
    public function businessAccountId(): string { return $this->businessAccountId; }
    public function oauthProviderAccountId(): string { return $this->oauthProviderAccountId; }
    public function providerType(): string { return $this->providerType; }
    public function returnUrl(): string { return $this->returnUrl; }
    public function issuedAt(): DateTimeImmutable { return $this->issuedAt; }
    public function expiresAt(): DateTimeImmutable { return $this->expiresAt; }
    public function consumedAt(): ?DateTimeImmutable { return $this->consumedAt; }
    public function resultMemberId(): ?string { return $this->resultMemberId; }
    public function resultExternalIdentityId(): ?string { return $this->resultExternalIdentityId; }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function isConsumed(): bool
    {
        return $this->consumedAt !== null;
    }

    public function hasCompleteResult(): bool
    {
        return $this->consumedAt !== null
            && $this->resultMemberId !== null
            && $this->resultExternalIdentityId !== null;
    }

    public function complete(string $memberId, string $externalIdentityId, DateTimeImmutable $consumedAt): self
    {
        if (trim($memberId) === '' || trim($externalIdentityId) === '') {
            throw new InvalidArgumentException('OAuth state completion requires member and external identity ids.');
        }
        if ($this->isConsumed()) {
            throw new InvalidArgumentException('OAuth state is already consumed.');
        }
        if ($consumedAt < $this->issuedAt || $consumedAt >= $this->expiresAt) {
            throw new InvalidArgumentException('OAuth state must be completed while valid.');
        }

        return new self(
            $this->id,
            $this->nonceHash,
            $this->tenantId,
            $this->businessAccountId,
            $this->oauthProviderAccountId,
            $this->providerType,
            $this->returnUrl,
            $this->issuedAt,
            $this->expiresAt,
            $consumedAt,
            $memberId,
            $externalIdentityId,
        );
    }
}
