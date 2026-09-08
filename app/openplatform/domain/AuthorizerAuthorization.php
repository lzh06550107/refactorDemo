<?php

declare(strict_types=1);

namespace app\openplatform\domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AuthorizerAuthorization
{
    /** @param list<string> $scopeSet */
    private function __construct(
        private string $componentPlatformId,
        private string $authorizerAppId,
        private string $status,
        private ?string $refreshTokenHash,
        private array $scopeSet,
        private DateTimeImmutable $providerUpdatedAt,
        private DateTimeImmutable $firstAuthorizedAt,
        private DateTimeImmutable $lastAuthorizedAt,
        private ?DateTimeImmutable $unauthorizedAt,
        private int $version,
    ) {
        if (trim($componentPlatformId) === '') {
            throw new InvalidArgumentException('componentPlatformId must not be empty.');
        }
        if (trim($authorizerAppId) === '') {
            throw new InvalidArgumentException('authorizerAppId must not be empty.');
        }
        if (!in_array($status, ['active', 'unauthorized'], true)) {
            throw new InvalidArgumentException('Unsupported authorizer authorization status.');
        }
        if ($status === 'active' && ($refreshTokenHash === null || !preg_match('/^[a-f0-9]{64}$/', $refreshTokenHash))) {
            throw new InvalidArgumentException('Active authorization requires a SHA-256 refresh token hash.');
        }
        if ($status === 'unauthorized' && $refreshTokenHash !== null) {
            throw new InvalidArgumentException('Unauthorized authorization must not keep a refresh token hash.');
        }
        if ($version < 1) {
            throw new InvalidArgumentException('Authorizer authorization version must be positive.');
        }
        if ($lastAuthorizedAt < $firstAuthorizedAt) {
            throw new InvalidArgumentException('lastAuthorizedAt must not precede firstAuthorizedAt.');
        }
    }

    /** @param list<string> $scopeSet */
    public static function reconstitute(
        string $componentPlatformId,
        string $authorizerAppId,
        string $status,
        ?string $refreshTokenHash,
        array $scopeSet,
        DateTimeImmutable $providerUpdatedAt,
        DateTimeImmutable $firstAuthorizedAt,
        DateTimeImmutable $lastAuthorizedAt,
        ?DateTimeImmutable $unauthorizedAt,
        int $version,
    ): self {
        return new self(
            $componentPlatformId,
            $authorizerAppId,
            $status,
            $refreshTokenHash,
            self::normalizeScopeSet($scopeSet),
            $providerUpdatedAt,
            $firstAuthorizedAt,
            $lastAuthorizedAt,
            $unauthorizedAt,
            $version,
        );
    }

    /** @param list<string> $scopeSet */
    public static function active(
        string $componentPlatformId,
        string $authorizerAppId,
        string $refreshTokenHash,
        array $scopeSet,
        DateTimeImmutable $providerUpdatedAt,
        DateTimeImmutable $firstAuthorizedAt,
        DateTimeImmutable $lastAuthorizedAt,
        int $version,
    ): self {
        return new self(
            $componentPlatformId,
            $authorizerAppId,
            'active',
            $refreshTokenHash,
            self::normalizeScopeSet($scopeSet),
            $providerUpdatedAt,
            $firstAuthorizedAt,
            $lastAuthorizedAt,
            null,
            $version,
        );
    }

    public function componentPlatformId(): string { return $this->componentPlatformId; }
    public function authorizerAppId(): string { return $this->authorizerAppId; }
    public function status(): string { return $this->status; }
    public function isActive(): bool { return $this->status === 'active'; }
    public function refreshTokenHash(): ?string { return $this->refreshTokenHash; }
    /** @return list<string> */
    public function scopeSet(): array { return $this->scopeSet; }
    public function providerUpdatedAt(): DateTimeImmutable { return $this->providerUpdatedAt; }
    public function firstAuthorizedAt(): DateTimeImmutable { return $this->firstAuthorizedAt; }
    public function lastAuthorizedAt(): DateTimeImmutable { return $this->lastAuthorizedAt; }
    public function unauthorizedAt(): ?DateTimeImmutable { return $this->unauthorizedAt; }
    public function version(): int { return $this->version; }

    public function acceptsSourceTimestamp(DateTimeImmutable $sourceTimestamp): bool
    {
        return $sourceTimestamp >= $this->providerUpdatedAt;
    }

    public function withUnauthorized(DateTimeImmutable $sourceTimestamp): self
    {
        if ($sourceTimestamp < $this->providerUpdatedAt) {
            return $this;
        }
        return new self(
            $this->componentPlatformId,
            $this->authorizerAppId,
            'unauthorized',
            null,
            $this->scopeSet,
            $sourceTimestamp,
            $this->firstAuthorizedAt,
            $this->lastAuthorizedAt,
            $sourceTimestamp,
            $this->version + 1,
        );
    }

    /** @param list<string> $scopeSet @return list<string> */
    private static function normalizeScopeSet(array $scopeSet): array
    {
        $normalized = [];
        foreach ($scopeSet as $scope) {
            $scope = trim((string) $scope);
            if ($scope !== '') {
                $normalized[$scope] = $scope;
            }
        }
        $normalized = array_values($normalized);
        sort($normalized, SORT_STRING);
        return $normalized;
    }
}
