<?php

declare(strict_types=1);

namespace modules\miniapp\domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class MiniAppSession
{
    private const TTL_SECONDS = 1800;

    private function __construct(
        private string $id,
        private string $tenantId,
        private string $accountId,
        private string $memberId,
        private string $externalIdentityId,
        private string $tokenHash,
        private ProtectedSessionKey $protectedSessionKey,
        private DateTimeImmutable $issuedAt,
        private DateTimeImmutable $expiresAt,
        private ?DateTimeImmutable $revokedAt,
    ) {
        foreach (['id' => $id, 'tenantId' => $tenantId, 'accountId' => $accountId, 'memberId' => $memberId, 'externalIdentityId' => $externalIdentityId] as $name => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($name . ' must not be empty.');
            }
        }
        if (preg_match('/^[a-f0-9]{64}$/', $tokenHash) !== 1) {
            throw new InvalidArgumentException('MiniApp session tokenHash must be a lowercase SHA-256 hex digest.');
        }
        if ($expiresAt <= $issuedAt) {
            throw new InvalidArgumentException('MiniApp session expiresAt must be after issuedAt.');
        }
        if ($revokedAt !== null && $revokedAt < $issuedAt) {
            throw new InvalidArgumentException('MiniApp session revokedAt must not be before issuedAt.');
        }
    }

    public static function issue(
        string $id,
        string $tenantId,
        string $accountId,
        string $memberId,
        string $externalIdentityId,
        string $tokenHash,
        ProtectedSessionKey $protectedSessionKey,
        DateTimeImmutable $issuedAt,
    ): self {
        return new self(
            $id,
            $tenantId,
            $accountId,
            $memberId,
            $externalIdentityId,
            $tokenHash,
            $protectedSessionKey,
            $issuedAt,
            $issuedAt->modify('+' . self::TTL_SECONDS . ' seconds'),
            null,
        );
    }

    public static function restore(
        string $id,
        string $tenantId,
        string $accountId,
        string $memberId,
        string $externalIdentityId,
        string $tokenHash,
        ProtectedSessionKey $protectedSessionKey,
        DateTimeImmutable $issuedAt,
        DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $revokedAt,
    ): self {
        return new self($id, $tenantId, $accountId, $memberId, $externalIdentityId, $tokenHash, $protectedSessionKey, $issuedAt, $expiresAt, $revokedAt);
    }

    public function revoke(DateTimeImmutable $at): self
    {
        if ($at < $this->issuedAt) {
            throw new InvalidArgumentException('MiniApp session cannot be revoked before issuance.');
        }
        if ($this->revokedAt !== null) {
            return $this;
        }
        return new self(
            $this->id,
            $this->tenantId,
            $this->accountId,
            $this->memberId,
            $this->externalIdentityId,
            $this->tokenHash,
            $this->protectedSessionKey,
            $this->issuedAt,
            $this->expiresAt,
            $at,
        );
    }

    public function isActiveAt(DateTimeImmutable $at): bool
    {
        return $this->revokedAt === null && $at >= $this->issuedAt && $at < $this->expiresAt;
    }

    public function id(): string { return $this->id; }
    public function tenantId(): string { return $this->tenantId; }
    public function accountId(): string { return $this->accountId; }
    public function memberId(): string { return $this->memberId; }
    public function externalIdentityId(): string { return $this->externalIdentityId; }
    public function tokenHash(): string { return $this->tokenHash; }
    public function protectedSessionKey(): ProtectedSessionKey { return $this->protectedSessionKey; }
    public function issuedAt(): DateTimeImmutable { return $this->issuedAt; }
    public function expiresAt(): DateTimeImmutable { return $this->expiresAt; }
    public function revokedAt(): ?DateTimeImmutable { return $this->revokedAt; }
}
