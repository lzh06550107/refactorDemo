<?php

declare(strict_types=1);

namespace modules\openplatform\application;

use app\common\audit\AuditEvent;
use app\common\contract\AuditLogger;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\openplatform\contract\AuthorizationIntentRepository;
use modules\openplatform\contract\AuthorizerAuthorizationRepository;
use modules\openplatform\contract\AuthorizerClient;
use modules\openplatform\contract\AuthorizerOwnershipRepository;
use modules\openplatform\domain\AuthenticatedComponentEvent;
use modules\openplatform\domain\AuthorizerAccountOwnership;
use modules\openplatform\domain\AuthorizerAuthorization;
use modules\openplatform\domain\AuthorizerMetadataRecord;
use DateTimeImmutable;
use Throwable;

final readonly class AuthorizationEventService
{
    public function __construct(
        private AuthorizationIntentRepository $intents,
        private AuthorizationCompletionService $completion,
        private ComponentAccessTokenService $componentTokens,
        private AuthorizerClient $authorizerClient,
        private AuthorizerAuthorizationRepository $authorizations,
        private AuditLogger $audit,
        private ?AuthorizerOwnershipRepository $ownerships = null,
        private ?AuthorizerConnectionService $connections = null,
        private ?AuthorizerMetadataSyncService $metadataSync = null,
    ) {
    }

    public function handle(
        AuthenticatedComponentEvent $event,
        DateTimeImmutable $now,
        string $requestId,
        string $traceId,
    ): void {
        match ($event->infoType()) {
            'authorized' => $this->handleAuthorized($event, $now, $requestId, $traceId),
            'updateauthorized' => $this->applyAuthorization($event, $now, $requestId, $traceId),
            'unauthorized' => $this->applyUnauthorized($event, $now, $requestId, $traceId),
            default => null,
        };
    }

    private function handleAuthorized(AuthenticatedComponentEvent $event, DateTimeImmutable $now, string $requestId, string $traceId): void
    {
        $preAuthCode = $event->preAuthCode();
        if ($preAuthCode !== null && trim($preAuthCode) !== '') {
            $intent = $this->intents->findByPreAuthCodeHash(
                $event->componentPlatformId(),
                hash('sha256', $preAuthCode),
            );
            if ($intent !== null) {
                $this->completion->completeIntent(
                    $intent,
                    (string) $event->authorizationCode(),
                    $now,
                    $requestId,
                    $traceId,
                    $event->sourceTimestamp(),
                    (string) $event->authorizerAppId(),
                );
                return;
            }
        }

        $this->applyAuthorization($event, $now, $requestId, $traceId);
    }

    private function applyAuthorization(AuthenticatedComponentEvent $event, DateTimeImmutable $now, string $requestId, string $traceId): void
    {
        $authorizerAppId = (string) $event->authorizerAppId();
        $authorizationCode = (string) $event->authorizationCode();
        $componentToken = $this->componentTokens->forPlatform($event->componentPlatformId(), $now);
        $provider = $this->authorizerClient->queryAuthorization(
            $componentToken->componentAppId(),
            $componentToken->accessToken(),
            $authorizationCode,
        );
        if (!hash_equals($authorizerAppId, $provider->authorizerAppId())) {
            $this->conflict();
        }

        $current = $this->authorizations->current($event->componentPlatformId(), $authorizerAppId);
        if ($current !== null) {
            if ($event->sourceTimestamp() < $current->providerUpdatedAt()) {
                return;
            }
            if ($event->sourceTimestamp() == $current->providerUpdatedAt()) {
                if ($this->sameActiveResult($current, $provider->refreshToken(), $provider->scopeSet())) {
                    // Replaying the same authoritative event may repair a projection that failed previously.
                    $this->projectActive($event, $now, $requestId, $traceId);
                    return;
                }
                $this->conflict();
            }
        }

        $authorization = AuthorizerAuthorization::active(
            $event->componentPlatformId(),
            $authorizerAppId,
            hash('sha256', $provider->refreshToken()),
            $provider->scopeSet(),
            $event->sourceTimestamp(),
            $current?->firstAuthorizedAt() ?? $event->sourceTimestamp(),
            $event->sourceTimestamp(),
            ($current?->version() ?? 0) + 1,
        );
        if (!$this->authorizations->saveFromAuthorization(
            $authorization,
            $provider->refreshToken(),
            $provider->accessToken(),
            $now->modify('+' . $provider->expiresIn() . ' seconds'),
        )) {
            $latest = $this->authorizations->current($event->componentPlatformId(), $authorizerAppId);
            if ($latest !== null && $latest->providerUpdatedAt() > $event->sourceTimestamp()) {
                return;
            }
            if ($latest !== null && $latest->providerUpdatedAt() == $event->sourceTimestamp() && $this->sameActiveResult($latest, $provider->refreshToken(), $provider->scopeSet())) {
                $this->projectActive($event, $now, $requestId, $traceId);
                return;
            }
            $this->conflict();
        }

        // Provider authorization truth is committed before any recoverable local projection work.
        $this->projectActive($event, $now, $requestId, $traceId);
        $this->auditLifecycle($event, $authorization, $requestId, $traceId, $now, 'active');
    }

    private function applyUnauthorized(AuthenticatedComponentEvent $event, DateTimeImmutable $now, string $requestId, string $traceId): void
    {
        $authorizerAppId = (string) $event->authorizerAppId();
        $current = $this->authorizations->current($event->componentPlatformId(), $authorizerAppId);
        if ($current === null || $event->sourceTimestamp() < $current->providerUpdatedAt()) {
            return;
        }
        if ($event->sourceTimestamp() == $current->providerUpdatedAt()) {
            if (!$current->isActive()) {
                $this->projectDisconnected($event, $now, $requestId, $traceId);
                return;
            }
            $this->conflict();
        }

        if (!$this->authorizations->markUnauthorized(
            $event->componentPlatformId(),
            $authorizerAppId,
            $event->sourceTimestamp(),
            $current->version(),
        )) {
            $latest = $this->authorizations->current($event->componentPlatformId(), $authorizerAppId);
            if ($latest === null || $latest->providerUpdatedAt() > $event->sourceTimestamp()) {
                return;
            }
            if ($latest->providerUpdatedAt() == $event->sourceTimestamp() && !$latest->isActive()) {
                $this->projectDisconnected($event, $now, $requestId, $traceId);
                return;
            }
            $this->conflict();
        }

        // Remote unauthorized never deletes Account/ownership/metadata/quota; only connection is disabled.
        $this->projectDisconnected($event, $now, $requestId, $traceId);
        $latest = $this->authorizations->current($event->componentPlatformId(), $authorizerAppId);
        if ($latest !== null) {
            $this->auditLifecycle($event, $latest, $requestId, $traceId, $now, 'unauthorized');
        }
    }

    private function projectActive(AuthenticatedComponentEvent $event, DateTimeImmutable $now, string $requestId, string $traceId): void
    {
        $metadata = null;
        if ($this->metadataSync !== null) {
            try {
                $metadata = $this->metadataSync->sync(
                    $event->componentPlatformId(),
                    (string) $event->authorizerAppId(),
                    $now,
                    'authorizer_lifecycle_event',
                );
            } catch (Throwable $e) {
                $this->auditProjectionFailure($event, $requestId, $traceId, $now, 'metadata', $e);
            }
        }

        if ($this->ownerships === null || $this->connections === null) {
            return;
        }
        $ownership = $this->ownerships->current(
            $event->componentPlatformId(),
            (string) $event->authorizerAppId(),
        );
        if ($ownership === null) {
            // Event-only authorization remains platform-level; never infer Tenant or create Account/quota.
            return;
        }
        if ($metadata !== null && !$this->compatibleType($ownership, $metadata)) {
            $this->auditProjectionTypeConflict($event, $ownership, $metadata, $requestId, $traceId, $now);
            return;
        }

        try {
            $this->connections->reconnect($ownership, $now);
        } catch (Throwable $e) {
            // Deleted/missing Account or projection failure needs manual/retry handling, not auth rollback.
            $this->auditProjectionFailure($event, $requestId, $traceId, $now, 'reconnect', $e, $ownership);
        }
    }

    private function projectDisconnected(AuthenticatedComponentEvent $event, DateTimeImmutable $now, string $requestId, string $traceId): void
    {
        if ($this->connections === null) {
            return;
        }
        try {
            $this->connections->disconnect(
                $event->componentPlatformId(),
                (string) $event->authorizerAppId(),
                $now,
            );
        } catch (Throwable $e) {
            // AuthorizerAuthorization remains authoritative even if the projection update is retried later.
            $this->auditProjectionFailure($event, $requestId, $traceId, $now, 'disconnect', $e);
        }
    }

    private function compatibleType(AuthorizerAccountOwnership $ownership, AuthorizerMetadataRecord $metadata): bool
    {
        return $ownership->accountType() === $metadata->accountType();
    }

    /** @param list<string> $scopeSet */
    private function sameActiveResult(AuthorizerAuthorization $current, string $refreshToken, array $scopeSet): bool
    {
        $normalized = array_values(array_unique(array_map(static fn (mixed $scope): string => trim((string) $scope), $scopeSet)));
        $normalized = array_values(array_filter($normalized, static fn (string $scope): bool => $scope !== ''));
        sort($normalized, SORT_STRING);
        return $current->isActive()
            && hash_equals((string) $current->refreshTokenHash(), hash('sha256', $refreshToken))
            && $current->scopeSet() === $normalized;
    }

    private function auditLifecycle(AuthenticatedComponentEvent $event, AuthorizerAuthorization $authorization, string $requestId, string $traceId, DateTimeImmutable $now, string $outcome): void
    {
        try {
            $this->audit->record(new AuditEvent(
                'external:wechat-openplatform',
                null,
                null,
                'openplatform.authorizer.lifecycle.' . $event->infoType(),
                'success',
                $requestId,
                $traceId,
                [
                    'component_platform_id' => $authorization->componentPlatformId(),
                    'authorizer_app_id' => $authorization->authorizerAppId(),
                    'authorization_version' => $authorization->version(),
                    'outcome' => $outcome,
                ],
                $now,
            ));
        } catch (Throwable) {
            // Provider event state is authoritative; audit failure must not undo it.
        }
    }

    private function auditProjectionFailure(
        AuthenticatedComponentEvent $event,
        string $requestId,
        string $traceId,
        DateTimeImmutable $now,
        string $stage,
        Throwable $error,
        ?AuthorizerAccountOwnership $ownership = null,
    ): void {
        try {
            $this->audit->record(new AuditEvent(
                'external:wechat-openplatform',
                $ownership?->tenantId(),
                $ownership?->accountId(),
                'openplatform.authorizer.projection.' . $stage,
                'failure',
                $requestId,
                $traceId,
                [
                    'component_platform_id' => $event->componentPlatformId(),
                    'authorizer_app_id' => (string) $event->authorizerAppId(),
                    'error_class' => $error::class,
                ],
                $now,
            ));
        } catch (Throwable) {
        }
    }

    private function auditProjectionTypeConflict(
        AuthenticatedComponentEvent $event,
        AuthorizerAccountOwnership $ownership,
        AuthorizerMetadataRecord $metadata,
        string $requestId,
        string $traceId,
        DateTimeImmutable $now,
    ): void {
        try {
            $this->audit->record(new AuditEvent(
                'external:wechat-openplatform',
                $ownership->tenantId(),
                $ownership->accountId(),
                'openplatform.authorizer.projection.metadata_type_conflict',
                'failure',
                $requestId,
                $traceId,
                [
                    'component_platform_id' => $event->componentPlatformId(),
                    'authorizer_app_id' => (string) $event->authorizerAppId(),
                    'ownership_account_type' => $ownership->accountType()->value,
                    'metadata_account_type' => $metadata->accountType()->value,
                ],
                $now,
            ));
        } catch (Throwable) {
        }
    }

    private function conflict(): never
    {
        throw new AppException(ErrorCode::CONFLICT, 'Conflicting OpenPlatform authorizer lifecycle state.', 409);
    }
}
