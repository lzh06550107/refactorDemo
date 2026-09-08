<?php

declare(strict_types=1);

namespace app\openplatform\application;

use app\common\audit\AuditEvent;
use app\common\contract\AuditLogger;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\openplatform\contract\AuthorizationIntentRepository;
use app\openplatform\contract\AuthorizerAuthorizationRepository;
use app\openplatform\contract\AuthorizerClient;
use app\openplatform\domain\AuthenticatedComponentEvent;
use app\openplatform\domain\AuthorizerAuthorization;
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
                return;
            }
            $this->conflict();
        }

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
                return;
            }
            $this->conflict();
        }

        $latest = $this->authorizations->current($event->componentPlatformId(), $authorizerAppId);
        if ($latest !== null) {
            $this->auditLifecycle($event, $latest, $requestId, $traceId, $now, 'unauthorized');
        }
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

    private function conflict(): never
    {
        throw new AppException(ErrorCode::CONFLICT, 'Conflicting OpenPlatform authorizer lifecycle state.', 409);
    }
}
