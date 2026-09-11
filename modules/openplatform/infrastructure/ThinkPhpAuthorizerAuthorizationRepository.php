<?php

declare(strict_types=1);

namespace modules\openplatform\infrastructure;

use modules\openplatform\contract\AuthorizerAuthorizationCredentialRepository;
use modules\openplatform\contract\OpenPlatformSecretCipher;
use modules\openplatform\domain\AuthorizerAccessToken;
use modules\openplatform\domain\AuthorizerAuthorization;
use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;

final readonly class ThinkPhpAuthorizerAuthorizationRepository implements AuthorizerAuthorizationCredentialRepository
{
    public function __construct(private OpenPlatformSecretCipher $cipher)
    {
    }

    public function current(string $componentPlatformId, string $authorizerAppId): ?AuthorizerAuthorization
    {
        $row = Db::table('authorizer_authorizations')->where([
            'component_platform_id' => $componentPlatformId,
            'authorizer_app_id' => $authorizerAppId,
        ])->find();
        return is_array($row) ? $this->mapAuthorization($row) : null;
    }

    public function currentRefreshToken(string $componentPlatformId, string $authorizerAppId): ?string
    {
        $row = Db::table('authorizer_authorizations')->where([
            'component_platform_id' => $componentPlatformId,
            'authorizer_app_id' => $authorizerAppId,
        ])->find();
        if (
            !is_array($row)
            || (string) ($row['status'] ?? '') !== 'active'
            || !is_string($row['refresh_token_ciphertext'] ?? null)
            || !is_string($row['refresh_token_key_version'] ?? null)
        ) {
            return null;
        }
        return $this->cipher->reveal(
            (string) $row['refresh_token_ciphertext'],
            (string) $row['refresh_token_key_version'],
        );
    }

    public function saveFromAuthorization(
        AuthorizerAuthorization $authorization,
        string $refreshToken,
        string $accessToken,
        DateTimeImmutable $accessTokenExpiresAt,
    ): bool {
        $protectedRefresh = $this->cipher->protect($refreshToken);
        $protectedAccess = $this->cipher->protect($accessToken);
        $issuedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return Db::transaction(function () use ($authorization, $accessTokenExpiresAt, $protectedRefresh, $protectedAccess, $issuedAt): bool {
            $platformId = $authorization->componentPlatformId();
            $authorizerAppId = $authorization->authorizerAppId();
            $key = [
                'component_platform_id' => $platformId,
                'authorizer_app_id' => $authorizerAppId,
            ];

            $current = Db::table('authorizer_authorizations')->where($key)->lock(true)->find();
            if (!is_array($current)) {
                if ($authorization->version() !== 1) {
                    return false;
                }
            } else {
                $actualVersion = (int) $current['version'];
                if ($authorization->version() !== $actualVersion + 1) {
                    return false;
                }
                $currentUpdatedAt = $this->date((string) $current['provider_updated_at']);
                if ($authorization->providerUpdatedAt() <= $currentUpdatedAt) {
                    return false;
                }
            }

            $authorizationRow = [
                'status' => 'active',
                'refresh_token_ciphertext' => $protectedRefresh['ciphertext'],
                'refresh_token_key_version' => $protectedRefresh['keyVersion'],
                'refresh_token_hash' => $authorization->refreshTokenHash(),
                'scope_json' => json_encode($authorization->scopeSet(), JSON_THROW_ON_ERROR),
                'provider_updated_at' => $this->sqlDate($authorization->providerUpdatedAt()),
                'first_authorized_at' => $this->sqlDate($authorization->firstAuthorizedAt()),
                'last_authorized_at' => $this->sqlDate($authorization->lastAuthorizedAt()),
                'unauthorized_at' => null,
                'version' => $authorization->version(),
            ];
            if (is_array($current)) {
                Db::table('authorizer_authorizations')->where($key)->update($authorizationRow);
            } else {
                Db::table('authorizer_authorizations')->insert($key + $authorizationRow);
            }

            $currentToken = Db::table('authorizer_access_tokens')->where($key)->lock(true)->find();
            $tokenVersion = is_array($currentToken) ? (int) $currentToken['version'] + 1 : 1;
            $tokenRow = [
                'token_ciphertext' => $protectedAccess['ciphertext'],
                'token_key_version' => $protectedAccess['keyVersion'],
                'issued_at' => $this->sqlDate($issuedAt),
                'expires_at' => $this->sqlDate($accessTokenExpiresAt),
                'version' => $tokenVersion,
            ];
            if (is_array($currentToken)) {
                Db::table('authorizer_access_tokens')->where($key)->update($tokenRow);
            } else {
                Db::table('authorizer_access_tokens')->insert($key + $tokenRow);
            }
            return true;
        });
    }

    public function markUnauthorized(
        string $componentPlatformId,
        string $authorizerAppId,
        DateTimeImmutable $sourceTimestamp,
        int $expectedVersion,
    ): bool {
        return Db::transaction(function () use ($componentPlatformId, $authorizerAppId, $sourceTimestamp, $expectedVersion): bool {
            $key = [
                'component_platform_id' => $componentPlatformId,
                'authorizer_app_id' => $authorizerAppId,
            ];
            $current = Db::table('authorizer_authorizations')->where($key)->lock(true)->find();
            if (
                !is_array($current)
                || (int) $current['version'] !== $expectedVersion
                || $sourceTimestamp <= $this->date((string) $current['provider_updated_at'])
            ) {
                return false;
            }

            Db::table('authorizer_authorizations')->where($key)->update([
                'status' => 'unauthorized',
                'refresh_token_ciphertext' => null,
                'refresh_token_key_version' => null,
                'refresh_token_hash' => null,
                'provider_updated_at' => $this->sqlDate($sourceTimestamp),
                'unauthorized_at' => $this->sqlDate($sourceTimestamp),
                'version' => $expectedVersion + 1,
            ]);
            Db::table('authorizer_access_tokens')->where($key)->delete();
            Db::table('authorizer_token_refresh_leases')->where($key)->delete();
            return true;
        });
    }

    public function compareAndSetRefresh(
        AuthorizerAuthorization $authorization,
        AuthorizerAccessToken $token,
        string $refreshToken,
        string $holderId,
        int $expectedAuthorizationVersion,
        int $expectedTokenVersion,
        DateTimeImmutable $now,
    ): bool {
        $protectedRefresh = $this->cipher->protect($refreshToken);
        $protectedAccess = $this->cipher->protect($token->accessToken());

        return Db::transaction(function () use ($authorization, $token, $holderId, $expectedAuthorizationVersion, $expectedTokenVersion, $now, $protectedRefresh, $protectedAccess): bool {
            $key = [
                'component_platform_id' => $authorization->componentPlatformId(),
                'authorizer_app_id' => $authorization->authorizerAppId(),
            ];

            $lease = Db::table('authorizer_token_refresh_leases')->where($key)->lock(true)->find();
            if (
                !is_array($lease)
                || !is_string($lease['holder_id'] ?? null)
                || !hash_equals($holderId, (string) $lease['holder_id'])
                || empty($lease['lease_expires_at'])
                || $this->date((string) $lease['lease_expires_at']) <= $now
            ) {
                return false;
            }

            $currentAuthorization = Db::table('authorizer_authorizations')->where($key)->lock(true)->find();
            if (
                !is_array($currentAuthorization)
                || (string) $currentAuthorization['status'] !== 'active'
                || (int) $currentAuthorization['version'] !== $expectedAuthorizationVersion
            ) {
                return false;
            }

            $currentToken = Db::table('authorizer_access_tokens')->where($key)->lock(true)->find();
            $actualTokenVersion = is_array($currentToken) ? (int) $currentToken['version'] : 0;
            if ($actualTokenVersion !== $expectedTokenVersion || $token->version() !== $expectedTokenVersion + 1) {
                return false;
            }
            if (!in_array($authorization->version(), [$expectedAuthorizationVersion, $expectedAuthorizationVersion + 1], true)) {
                return false;
            }

            Db::table('authorizer_authorizations')->where($key)->update([
                'refresh_token_ciphertext' => $protectedRefresh['ciphertext'],
                'refresh_token_key_version' => $protectedRefresh['keyVersion'],
                'refresh_token_hash' => $authorization->refreshTokenHash(),
                'scope_json' => json_encode($authorization->scopeSet(), JSON_THROW_ON_ERROR),
                'provider_updated_at' => $this->sqlDate($authorization->providerUpdatedAt()),
                'first_authorized_at' => $this->sqlDate($authorization->firstAuthorizedAt()),
                'last_authorized_at' => $this->sqlDate($authorization->lastAuthorizedAt()),
                'unauthorized_at' => null,
                'status' => 'active',
                'version' => $authorization->version(),
            ]);

            $tokenRow = [
                'token_ciphertext' => $protectedAccess['ciphertext'],
                'token_key_version' => $protectedAccess['keyVersion'],
                'issued_at' => $this->sqlDate($token->issuedAt()),
                'expires_at' => $this->sqlDate($token->expiresAt()),
                'version' => $token->version(),
            ];
            if (is_array($currentToken)) {
                Db::table('authorizer_access_tokens')->where($key)->update($tokenRow);
            } else {
                Db::table('authorizer_access_tokens')->insert($key + $tokenRow);
            }
            return true;
        });
    }

    private function mapAuthorization(array $row): AuthorizerAuthorization
    {
        $scopeSet = json_decode((string) $row['scope_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($scopeSet)) {
            $scopeSet = [];
        }
        return AuthorizerAuthorization::reconstitute(
            (string) $row['component_platform_id'],
            (string) $row['authorizer_app_id'],
            (string) $row['status'],
            $this->nullableString($row['refresh_token_hash'] ?? null),
            array_values($scopeSet),
            $this->date((string) $row['provider_updated_at']),
            $this->date((string) $row['first_authorized_at']),
            $this->date((string) $row['last_authorized_at']),
            empty($row['unauthorized_at']) ? null : $this->date((string) $row['unauthorized_at']),
            (int) $row['version'],
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
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
