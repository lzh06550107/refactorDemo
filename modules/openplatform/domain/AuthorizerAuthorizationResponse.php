<?php

declare(strict_types=1);

namespace modules\openplatform\domain;

use InvalidArgumentException;

final readonly class AuthorizerAuthorizationResponse
{
    /** @param list<string> $scopeSet */
    public function __construct(
        private string $authorizerAppId,
        private string $accessToken,
        private string $refreshToken,
        private int $expiresIn,
        array $scopeSet,
    ) {
        if (trim($authorizerAppId) === '' || trim($accessToken) === '' || trim($refreshToken) === '' || $expiresIn <= 0) {
            throw new InvalidArgumentException('Invalid OpenPlatform authorizer authorization response.');
        }
        $this->scopeSet = self::normalizeScopeSet($scopeSet);
    }

    /** @var list<string> */
    private array $scopeSet;

    public function authorizerAppId(): string { return $this->authorizerAppId; }
    public function accessToken(): string { return $this->accessToken; }
    public function refreshToken(): string { return $this->refreshToken; }
    public function expiresIn(): int { return $this->expiresIn; }
    /** @return list<string> */
    public function scopeSet(): array { return $this->scopeSet; }

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
