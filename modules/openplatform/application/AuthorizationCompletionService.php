<?php

declare(strict_types=1);

namespace modules\openplatform\application;

use app\common\audit\AuditEvent;
use app\common\contract\AuditLogger;
use app\common\contract\TransactionManager;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\openplatform\contract\AuthorizationIntentRepository;
use modules\openplatform\contract\AuthorizerAccountBinding;
use modules\openplatform\contract\AuthorizerAuthorizationRepository;
use modules\openplatform\contract\AuthorizerClient;
use modules\openplatform\contract\AuthorizerProvisioningRepository;
use modules\openplatform\contract\ProvisioningJobRepository;
use modules\openplatform\domain\AuthorizationIntent;
use modules\openplatform\domain\AuthorizationIntentMode;
use modules\openplatform\domain\AuthorizerAuthorization;
use modules\openplatform\domain\AuthorizerAuthorizationResult;
use modules\openplatform\domain\AuthorizerProvisioning;
use modules\openplatform\domain\ProvisioningJob;
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
        private ?AuthorizerProvisioningRepository $provisionings = null,
        private ?ProvisioningJobRepository $provisioningJobs = null,
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
        ?DateTimeImmutable $providerUpdatedAt = null,
        ?string $expectedAuthorizerAppId = null,
    ): AuthorizerAuthorizationResult {
        if (trim($authorizationCode) === '') {
            $this->unauthorized();
        }

        $current = $this->intents->findByStateHash($intent->stateHash());
        if ($current === null) {
            $this->unauthorized();
        }
        if ($current->completed()) {
            return $this->completedResult($current);
        }
        if (!$current->validAt($now)) {
            $this->unauthorized();
        }
        if ($current->mode() === AuthorizationIntentMode::AUTO_PROVISION_ACCOUNT) {
            $this->assertAutoProvisioningConfigured();
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
                return $this->completedResult($latest);
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
            if ($expectedAuthorizerAppId !== null && !hash_equals($expectedAuthorizerAppId, $provider->authorizerAppId())) {
                $this->conflict();
            }
        } catch (Throwable $e) {
            $this->releaseClaim($claimed->id(), $holderId);
            throw $e;
        }

        $authorizationTimestamp = $providerUpdatedAt ?? $now;
        try {
            /** @var array{0:AuthorizerAuthorization,1:?string} $completion */
            $completion = $this->transactions->run(function () use ($claimed, $holderId, $provider, $now, $authorizationTimestamp): array {
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
                if ($existing !== null && $authorizationTimestamp < $existing->providerUpdatedAt()) {
                    $this->conflict();
                }
                $firstAuthorizedAt = $existing?->firstAuthorizedAt() ?? $authorizationTimestamp;
                $nextVersion = ($existing?->version() ?? 0) + 1;
                $authorization = AuthorizerAuthorization::active(
                    $claimed->componentPlatformId(),
                    $provider->authorizerAppId(),
                    hash('sha256', $provider->refreshToken()),
                    $provider->scopeSet(),
                    $authorizationTimestamp,
                    $firstAuthorizedAt,
                    $authorizationTimestamp,
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

                $provisioningId = null;
                if ($claimed->mode() === AuthorizationIntentMode::AUTO_PROVISION_ACCOUNT) {
                    $this->assertAutoProvisioningConfigured();
                    $provisioningId = $this->provisioningIdForIntent($claimed->id());
                    if ($this->provisionings?->findBySourceIntent($claimed->id()) !== null) {
                        $this->conflict();
                    }
                    $provisioning = AuthorizerProvisioning::pending(
                        $provisioningId,
                        $claimed->id(),
                        $claimed->tenantId(),
                        $claimed->componentPlatformId(),
                        $provider->authorizerAppId(),
                        $now,
                    );
                    $this->provisionings?->insert($provisioning);
                    $this->provisioningJobs?->insert(ProvisioningJob::ready($provisioningId, $now, $now));
                } else {
                    $targetAccountId = $claimed->targetAccountId();
                    if ($targetAccountId === null || $targetAccountId === '') {
                        $this->conflict();
                    }
                    $this->binding->bindExistingAccount(
                        $claimed->tenantId(),
                        $targetAccountId,
                        $claimed->componentPlatformId(),
                        $provider->authorizerAppId(),
                    );
                }

                if (!$this->intents->complete(
                    $claimed->id(),
                    $holderId,
                    $provider->authorizerAppId(),
                    $now,
                    $claimed->version(),
                )) {
                    $this->conflict();
                }

                return [$authorization, $provisioningId];
            });
        } catch (Throwable $e) {
            $this->releaseClaim($claimed->id(), $holderId);
            throw $e;
        }

        [$authorization, $provisioningId] = $completion;
        $this->auditCompletion($claimed, $authorization, $requestId, $traceId, $now, $provisioningId);
        return $provisioningId === null
            ? AuthorizerAuthorizationResult::completed($authorization->authorizerAppId())
            : AuthorizerAuthorizationResult::provisioning($authorization->authorizerAppId(), $provisioningId);
    }

    private function completedResult(AuthorizationIntent $intent): AuthorizerAuthorizationResult
    {
        $authorizerAppId = (string) $intent->completedAuthorizerAppId();
        if ($intent->mode() !== AuthorizationIntentMode::AUTO_PROVISION_ACCOUNT) {
            return AuthorizerAuthorizationResult::completed($authorizerAppId);
        }

        $this->assertAutoProvisioningConfigured();
        $provisioning = $this->provisionings?->findBySourceIntent($intent->id());
        if ($provisioning === null) {
            $this->conflict();
        }
        return AuthorizerAuthorizationResult::provisioning($authorizerAppId, $provisioning->id());
    }

    private function auditCompletion(
        AuthorizationIntent $intent,
        AuthorizerAuthorization $authorization,
        string $requestId,
        string $traceId,
        DateTimeImmutable $now,
        ?string $provisioningId,
    ): void {
        try {
            $metadata = [
                'component_platform_id' => $authorization->componentPlatformId(),
                'authorizer_app_id' => $authorization->authorizerAppId(),
                'authorization_version' => $authorization->version(),
                'scope_count' => count($authorization->scopeSet()),
                'outcome' => $provisioningId === null ? 'completed' : 'provisioning',
            ];
            if ($provisioningId !== null) {
                $metadata['provisioning_id'] = $provisioningId;
            }
            $this->audit->record(new AuditEvent(
                'external:wechat-openplatform',
                $intent->tenantId(),
                $intent->targetAccountId(),
                'openplatform.authorizer.authorization.complete',
                'success',
                $requestId,
                $traceId,
                $metadata,
                $now,
            ));

            if ($provisioningId !== null) {
                $this->audit->record(new AuditEvent(
                    'external:wechat-openplatform',
                    $intent->tenantId(),
                    null,
                    OpenPlatformAudit::PROVISIONING_CREATED,
                    'success',
                    $requestId,
                    $traceId,
                    [
                        'component_platform_id' => $authorization->componentPlatformId(),
                        'authorizer_app_id' => $authorization->authorizerAppId(),
                        'intent_id' => $intent->id(),
                        'provisioning_id' => $provisioningId,
                    ],
                    $now,
                ));
            }
        } catch (Throwable) {
            // Post-commit audit failure must not undo a completed authorization/provisioning trigger.
        }
    }

    private function assertAutoProvisioningConfigured(): void
    {
        if ($this->provisionings === null || $this->provisioningJobs === null) {
            throw new AppException(
                ErrorCode::SERVICE_UNAVAILABLE,
                'OpenPlatform auto provisioning persistence is not configured.',
                503,
            );
        }
    }

    private function provisioningIdForIntent(string $intentId): string
    {
        return hash('sha256', 'openplatform-provisioning:' . $intentId);
    }

    private function releaseClaim(string $intentId, string $holderId): void
    {
        try { $this->intents->releaseClaim($intentId, $holderId); }
        catch (Throwable) { /* claim expiry is recovery */ }
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
