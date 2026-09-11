<?php

declare(strict_types=1);

namespace modules\openplatform\application;

use app\common\audit\AuditEvent;
use app\common\contract\AuditLogger;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\openplatform\contract\ComponentCredentialProvider;
use modules\openplatform\contract\ComponentPlatformRepository;
use modules\openplatform\contract\ComponentRefreshLeaseRepository;
use modules\openplatform\contract\ComponentTicketRepository;
use modules\openplatform\contract\ComponentTokenClient;
use modules\openplatform\contract\ComponentTokenRepository;
use modules\openplatform\domain\ComponentAccessToken;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final readonly class ComponentAccessTokenService
{
    public function __construct(
        private ComponentPlatformRepository $platforms,
        private ComponentTicketRepository $tickets,
        private ComponentTokenRepository $tokens,
        private ComponentRefreshLeaseRepository $leases,
        private ComponentCredentialProvider $credentials,
        private ComponentTokenClient $client,
        private AuditLogger $audit,
        private int $refreshSkewSeconds = 300,
        private int $leaseSeconds = 30,
    ) {
    }

    public function forPlatform(string $componentPlatformId, ?DateTimeImmutable $now = null): ComponentAccessToken
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $platform = $this->platforms->findById($componentPlatformId);
        if ($platform === null || !$platform->enabled()) {
            throw new AppException(ErrorCode::NOT_FOUND, 'OpenPlatform component platform not found.', 404);
        }

        $current = $this->tokens->current($componentPlatformId);
        if ($current !== null && $current->outsideRefreshSkew($now, $this->refreshSkewSeconds)) {
            return $current;
        }

        $holderId = bin2hex(random_bytes(16));
        $lease = $this->leases->tryAcquire($componentPlatformId, $holderId, $now, $this->leaseSeconds);
        if ($lease === null) {
            $latest = $this->tokens->current($componentPlatformId);
            if ($latest !== null && $latest->usableAt($now)) {
                return $latest;
            }
            throw new AppException(ErrorCode::SERVICE_UNAVAILABLE, 'OpenPlatform component token refresh is already in progress.', 503);
        }

        try {
            $current = $this->tokens->current($componentPlatformId);
            if ($current !== null && $current->outsideRefreshSkew($now, $this->refreshSkewSeconds)) {
                return $current;
            }

            $ticket = $this->tickets->current($componentPlatformId);
            if ($ticket === null) {
                throw new AppException(ErrorCode::SERVICE_UNAVAILABLE, 'OpenPlatform component verify ticket is not available.', 503);
            }
            $appSecret = $this->credentials->secretFor($platform->appSecretRef());

            try {
                $provider = $this->client->refresh($platform, $appSecret, $ticket->ticket());
            } catch (AppException $e) {
                if ($e->errorCode() === ErrorCode::BAD_GATEWAY && $current !== null && $current->usableAt($now)) {
                    return $current;
                }
                throw $e;
            }

            $expectedVersion = $current?->version() ?? 0;
            $token = new ComponentAccessToken(
                $componentPlatformId,
                $platform->componentAppId(),
                $provider->accessToken(),
                $now,
                $now->modify('+' . $provider->expiresIn() . ' seconds'),
                $expectedVersion + 1,
            );
            if ($this->tokens->compareAndSet($token, $holderId, $expectedVersion, $now)) {
                $this->auditRefresh($token, $provider->expiresIn(), $now);
                return $token;
            }

            $latest = $this->tokens->current($componentPlatformId);
            if ($latest !== null && $latest->usableAt($now)) {
                return $latest;
            }
            throw new AppException(ErrorCode::SERVICE_UNAVAILABLE, 'OpenPlatform component token refresh lost ownership.', 503);
        } finally {
            try {
                $this->leases->release($componentPlatformId, $holderId);
            } catch (Throwable) {
                // Lease expiry is the recovery mechanism; release failure cannot overwrite token state.
            }
        }
    }

    private function auditRefresh(ComponentAccessToken $token, int $expiresIn, DateTimeImmutable $now): void
    {
        try {
            $id = bin2hex(random_bytes(16));
            $this->audit->record(new AuditEvent(
                'system:openplatform-token-refresh',
                null,
                null,
                'openplatform.component_access_token.refresh',
                'success',
                $id,
                $id,
                [
                    'component_platform_id' => $token->componentPlatformId(),
                    'component_app_id' => $token->componentAppId(),
                    'token_version' => $token->version(),
                    'expires_in' => $expiresIn,
                    'outcome' => 'refreshed',
                ],
                $now,
            ));
        } catch (Throwable) {
            // Token CAS is authoritative; audit transport failure must not roll it back.
        }
    }
}
