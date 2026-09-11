<?php

declare(strict_types=1);

namespace modules\openplatform\application;

use app\common\audit\AuditEvent;
use app\common\contract\AuditLogger;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\openplatform\contract\AuthorizerAuthorizationCredentialRepository;
use modules\openplatform\contract\AuthorizerClient;
use modules\openplatform\contract\AuthorizerRefreshLeaseRepository;
use modules\openplatform\contract\AuthorizerTokenRepository;
use modules\openplatform\contract\ComponentPlatformRepository;
use modules\openplatform\domain\AuthorizerAccessToken;
use modules\openplatform\domain\AuthorizerAuthorization;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final readonly class AuthorizerAccessTokenService
{
    public function __construct(
        private ComponentPlatformRepository $platforms,
        private AuthorizerAuthorizationCredentialRepository $authorizations,
        private AuthorizerTokenRepository $tokens,
        private AuthorizerRefreshLeaseRepository $leases,
        private ComponentAccessTokenService $componentTokens,
        private AuthorizerClient $client,
        private AuditLogger $audit,
        private int $refreshSkewSeconds = 300,
        private int $leaseSeconds = 30,
    ) {
    }

    public function forAuthorizer(
        string $componentPlatformId,
        string $authorizerAppId,
        ?DateTimeImmutable $now = null,
    ): AuthorizerAccessToken {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $platform = $this->platforms->findById($componentPlatformId);
        if ($platform === null || !$platform->enabled()) {
            throw new AppException(ErrorCode::NOT_FOUND, 'OpenPlatform component platform not found.', 404);
        }

        $authorization = $this->authorizations->current($componentPlatformId, $authorizerAppId);
        if ($authorization === null || !$authorization->isActive()) {
            throw new AppException(ErrorCode::FORBIDDEN, 'OpenPlatform authorizer authorization is not active.', 403);
        }

        $current = $this->tokens->current($componentPlatformId, $authorizerAppId);
        if ($current !== null && $current->outsideRefreshSkew($now, $this->refreshSkewSeconds)) {
            return $current;
        }

        $holderId = bin2hex(random_bytes(16));
        $lease = $this->leases->tryAcquire(
            $componentPlatformId,
            $authorizerAppId,
            $holderId,
            $now,
            $this->leaseSeconds,
        );
        if ($lease === null) {
            $latest = $this->tokens->current($componentPlatformId, $authorizerAppId);
            if ($latest !== null && $latest->usableAt($now)) {
                return $latest;
            }
            throw new AppException(ErrorCode::SERVICE_UNAVAILABLE, 'OpenPlatform authorizer token refresh is already in progress.', 503);
        }

        try {
            $current = $this->tokens->current($componentPlatformId, $authorizerAppId);
            if ($current !== null && $current->outsideRefreshSkew($now, $this->refreshSkewSeconds)) {
                return $current;
            }

            $componentToken = $this->componentTokens->forPlatform($componentPlatformId, $now);
            $refreshToken = $this->authorizations->currentRefreshToken($componentPlatformId, $authorizerAppId);
            if ($refreshToken === null || trim($refreshToken) === '') {
                throw new AppException(ErrorCode::FORBIDDEN, 'OpenPlatform authorizer authorization is not active.', 403);
            }

            try {
                $provider = $this->client->refreshAuthorizerToken(
                    $platform->componentAppId(),
                    $componentToken->accessToken(),
                    $authorizerAppId,
                    $refreshToken,
                );
            } catch (AppException $e) {
                if ($e->errorCode() === ErrorCode::BAD_GATEWAY && $current !== null && $current->usableAt($now)) {
                    return $current;
                }
                throw $e;
            }

            $expectedAuthorizationVersion = $authorization->version();
            $expectedTokenVersion = $current?->version() ?? 0;
            $rotatedRefreshToken = $provider->refreshToken();
            $nextRefreshToken = $rotatedRefreshToken ?? $refreshToken;
            $refreshRotated = $rotatedRefreshToken !== null
                && !hash_equals(hash('sha256', $refreshToken), hash('sha256', $rotatedRefreshToken));

            $nextAuthorization = $refreshRotated
                ? AuthorizerAuthorization::active(
                    $authorization->componentPlatformId(),
                    $authorization->authorizerAppId(),
                    hash('sha256', $nextRefreshToken),
                    $authorization->scopeSet(),
                    $authorization->providerUpdatedAt(),
                    $authorization->firstAuthorizedAt(),
                    $authorization->lastAuthorizedAt(),
                    $expectedAuthorizationVersion + 1,
                )
                : $authorization;

            $token = new AuthorizerAccessToken(
                $componentPlatformId,
                $authorizerAppId,
                $provider->accessToken(),
                $now,
                $now->modify('+' . $provider->expiresIn() . ' seconds'),
                $expectedTokenVersion + 1,
            );

            if ($this->authorizations->compareAndSetRefresh(
                $nextAuthorization,
                $token,
                $nextRefreshToken,
                $holderId,
                $expectedAuthorizationVersion,
                $expectedTokenVersion,
                $now,
            )) {
                $this->auditRefresh($nextAuthorization, $token, $provider->expiresIn(), $refreshRotated, $now);
                return $token;
            }

            $latest = $this->tokens->current($componentPlatformId, $authorizerAppId);
            if ($latest !== null && $latest->usableAt($now)) {
                return $latest;
            }
            throw new AppException(ErrorCode::SERVICE_UNAVAILABLE, 'OpenPlatform authorizer token refresh lost ownership.', 503);
        } finally {
            try {
                $this->leases->release($componentPlatformId, $authorizerAppId, $holderId);
            } catch (Throwable) {
                // Lease expiry is the recovery mechanism; release failure cannot roll back a successful CAS.
            }
        }
    }

    private function auditRefresh(
        AuthorizerAuthorization $authorization,
        AuthorizerAccessToken $token,
        int $expiresIn,
        bool $refreshRotated,
        DateTimeImmutable $now,
    ): void {
        try {
            $id = bin2hex(random_bytes(16));
            $this->audit->record(new AuditEvent(
                'system:openplatform-authorizer-token-refresh',
                null,
                null,
                'openplatform.authorizer_access_token.refresh',
                'success',
                $id,
                $id,
                [
                    'component_platform_id' => $token->componentPlatformId(),
                    'authorizer_app_id' => $token->authorizerAppId(),
                    'authorization_version' => $authorization->version(),
                    'token_version' => $token->version(),
                    'expires_in' => $expiresIn,
                    'refresh_rotated' => $refreshRotated,
                    'outcome' => 'refreshed',
                ],
                $now,
            ));
        } catch (Throwable) {
            // Token/refresh CAS is authoritative; audit transport failure must not roll it back.
        }
    }
}
