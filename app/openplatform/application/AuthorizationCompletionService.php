<?php

declare(strict_types=1);

namespace app\openplatform\application;

use app\common\audit\AuditEvent;
use app\common\contract\AuditLogger;
use app\common\contract\TransactionManager;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\openplatform\contract\AuthorizationIntentRepository;
use app\openplatform\contract\AuthorizerAccountBinding;
use app\openplatform\contract\AuthorizerAuthorizationRepository;
use app\openplatform\contract\AuthorizerClient;
use app\openplatform\domain\AuthorizationIntent;
use app\openplatform\domain\AuthorizerAuthorization;
use app\openplatform\domain\AuthorizerAuthorizationResult;
use DateTimeImmutable;
use Throwable;

final readonly class AuthorizationCompletionService
{
    public function __construct(
        private AuthorizationIntentRepository $intents,
        private ComponentAccessTokenService $componentTokens,
        private AuthorizerClient $authorizerClient,
        private AuthorizerAuthorizationRepository $authorizations,
        private AuthorizerAccountBinding $binding,
        private TransactionManager $transactions,
        private AuditLogger $audit,
        private int $claimSeconds = 30,
    ) {
        if ($claimSeconds <= 0) {
            throw new \InvalidArgumentException('Authorization completion claim TTL must be positive.');
        }
    }

    public function completeIntent(
        AuthorizationIntent $intent,
        string $authorizationCode,
        DateTimeImmutable $now,
        string $requestId,
        string $traceId,
    ): AuthorizerAuthorizationResult {
        if (trim($authorizationCode) === '') {
            $this->unauthorized();
        }

        $current = $this->intents->findByStateHash($intent->stateHash());
        if ($current === null) {
            $this->unauthorized();
        }
        if ($current->completed()) {
            return AuthorizerAuthorizationResult::completed((string) $current->completedAuthorizerAppId());
        }
        if (!$current->validAt($now)) {
            $this->unauthorized();
        }

        $holderId = bin2hex(random_bytes(16));
        $claimed = $this->intents->tryClaim(
            $current->id(),
            $holderId,
            $now,
            $this->claimSeconds,
            $current->version(),
        );
        if ($claimed === null) {
            $latest = $this->intents->findByStateHash($intent->stateHash());
            if ($latest !== null && $latest->completed()) {
                return AuthorizerAuthorizationResult::completed((string) $latest->completedAuthorizerAppId());
            }
            if ($latest !== null && $latest->validAt($now)) {
                return AuthorizerAuthorizationResult::processing();
            }
            $this->unauthorized();
        }

        try {
            $componentToken = $this->componentTokens->forPlatform($claimed->componentPlatformId(), $now);
            $provider = $this->authorizerClient->queryAuthorization(
                $componentToken->componentAppId(),
                $componentToken->accessToken(),
                $authorizationCode,
            );
        } catch (Throwable $e) {
            $this->releaseClaim($claimed->id(), $holderId);
            throw $e;
        }

        try {
            /** @var AuthorizerAuthorization $authorization */
            $authorization = $this->transactions->run(function () use ($claimed, $holderId, $provider, $now): AuthorizerAuthorization {
                $lockedIntent = $this->intents->findByStateHash($claimed->stateHash());
                if (
                    $lockedIntent === null
                    || $lockedIntent->completed()
                    || $lockedIntent->claimHolderId() !== $holderId
                    || $lockedIntent->version() !== $claimed->version()
                    || $lockedIntent->claimExpiresAt() === null
                    || $lockedIntent->claimExpiresAt() <= $now
                ) {
                    $this->conflict();
                }

                $existing = $this->authorizations->current(
                    $claimed->componentPlatformId(),
                    $provider->authorizerAppId(),
                );
                $firstAuthorizedAt = $existing?->firstAuthorizedAt() ?? $now;
                $nextVersion = ($existing?->version() ?? 0) + 1;
                $authorization = AuthorizerAuthorization::active(
                    $claimed->componentPlatformId(),
                    $provider->authorizerAppId(),
                    hash('sha256', $provider->refreshToken()),
                    $provider->scopeSet(),
                    $now,
                    $firstAuthorizedAt,
                    $now,
                    $nextVersion,
                );

                if (!$this->authorizations->saveFromAuthorization(
                    $authorization,
                    $provider->refreshToken(),
                    $provider->accessToken(),
                    $now->modify('+' . $provider->expiresIn() . ' seconds'),
                )) {
                    $this->conflict();
                }

                $this->binding->bindExistingAccount(
                    $claimed->tenantId(),
                    $claimed->targetAccountId(),
                    $claimed->componentPlatformId(),
                    $provider->authorizerAppId(),
                );

                if (!$this->intents->complete(
                    $claimed->id(),
                    $holderId,
                    $provider->authorizerAppId(),
                    $now,
                    $claimed->version(),
                )) {
                    $this->conflict();
                }

                return $authorization;
            });
        } catch (Throwable $e) {
            $this->releaseClaim($claimed->id(), $holderId);
            throw $e;
        }

        $this->auditCompletion($claimed, $authorization, $requestId, $traceId, $now);
        return AuthorizerAuthorizationResult::completed($authorization->authorizerAppId());
    }

    private function auditCompletion(
        AuthorizationIntent $intent,
        AuthorizerAuthorization $authorization,
        string $requestId,
        string $traceId,
        DateTimeImmutable $now,
    ): void {
        try {
            $this->audit->record(new AuditEvent(
                'external:wechat-openplatform',
                $intent->tenantId(),
                $intent->targetAccountId(),
                'openplatform.authorizer.authorization.complete',
                'success',
                $requestId,
                $traceId,
                [
                    'component_platform_id' => $authorization->componentPlatformId(),
                    'authorizer_app_id' => $authorization->authorizerAppId(),
                    'authorization_version' => $authorization->version(),
                    'scope_count' => count($authorization->scopeSet()),
                    'outcome' => 'completed',
                ],
                $now,
            ));
        } catch (Throwable) {
            // Post-commit audit failure must not undo a completed authorization.
        }
    }

    private function releaseClaim(string $intentId, string $holderId): void
    {
        try {
            $this->intents->releaseClaim($intentId, $holderId);
        } catch (Throwable) {
            // Claim expiry is the recovery mechanism; release failure cannot mask root cause.
        }
    }

    private function unauthorized(): never
    {
        throw new AppException(ErrorCode::UNAUTHORIZED, 'Invalid or expired OpenPlatform authorization intent.', 401);
    }

    private function conflict(): never
    {
        throw new AppException(ErrorCode::CONFLICT, 'OpenPlatform authorization completion lost ownership.', 409);
    }
}
