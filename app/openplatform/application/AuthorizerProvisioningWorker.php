<?php

declare(strict_types=1);

namespace app\openplatform\application;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\openplatform\contract\AuthorizerAccountFinalizer;
use app\openplatform\contract\AuthorizerAuthorizationRepository;
use app\openplatform\contract\AuthorizerMetadataRepository;
use app\openplatform\contract\AuthorizerOwnershipRepository;
use app\openplatform\contract\AuthorizerProvisioningRepository;
use app\openplatform\contract\ProvisioningJobRepository;
use app\openplatform\domain\AuthorizerMetadataRecord;
use app\openplatform\domain\AuthorizerOwnershipResolution;
use app\openplatform\domain\AuthorizerProvisioning;
use app\openplatform\domain\AuthorizerProvisioningStatus;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

final readonly class AuthorizerProvisioningWorker
{
    private const CLAIM_TTL_SECONDS = 60;
    private const MAX_AUTOMATIC_ATTEMPTS = 10;

    public function __construct(
        private ProvisioningJobRepository $jobs,
        private AuthorizerProvisioningRepository $provisionings,
        private AuthorizerAuthorizationRepository $authorizations,
        private AuthorizerMetadataSyncService $metadataSync,
        private AuthorizerOwnershipResolver $ownershipResolver,
        private AuthorizerOwnershipRepository $ownerships,
        private AuthorizerConnectionService $connections,
        private ?AuthorizerProvisioningQuotaService $quota = null,
        private ?AuthorizerAccountFinalizer $finalizer = null,
        private ?AuthorizerMetadataRepository $metadata = null,
    ) {
    }

    public function runOne(string $provisioningId, DateTimeImmutable $now): void
    {
        $provisioningId = trim($provisioningId);
        if ($provisioningId === '') {
            throw new InvalidArgumentException('provisioningId must not be empty.');
        }

        $holderId = bin2hex(random_bytes(16));
        $job = $this->jobs->tryClaim(
            $provisioningId,
            $holderId,
            $now,
            self::CLAIM_TTL_SECONDS,
        );
        if ($job === null) {
            return;
        }

        $provisioning = $this->provisionings->find($provisioningId);
        if ($provisioning === null) {
            $this->jobs->dead($provisioningId, $holderId, 'PROVISIONING_NOT_FOUND');
            return;
        }

        if ($this->isTerminal($provisioning->status())) {
            $this->jobs->complete($provisioningId, $holderId);
            return;
        }

        $authorization = $this->authorizations->current(
            $provisioning->componentPlatformId(),
            $provisioning->authorizerAppId(),
        );
        if ($authorization === null || !$authorization->isActive()) {
            $next = $provisioning->authorizationInactive($now);
            if ($this->save($provisioning, $next, $provisioningId, $holderId, $now)) {
                $this->jobs->complete($provisioningId, $holderId);
            }
            return;
        }

        $metadataRecord = null;
        if (in_array($provisioning->status(), [
            AuthorizerProvisioningStatus::PENDING_METADATA,
            AuthorizerProvisioningStatus::METADATA_FAILED,
        ], true)) {
            try {
                $metadataRecord = $this->metadataSync->sync(
                    $provisioning->componentPlatformId(),
                    $provisioning->authorizerAppId(),
                    $now,
                    'provisioning_worker',
                );
            } catch (Throwable $e) {
                $this->handleMetadataFailure($provisioning, $provisioningId, $holderId, $job->attemptCount(), $now, $e);
                return;
            }

            $next = $provisioning->withMetadata(
                $metadataRecord->accountType(),
                $metadataRecord->version(),
                $now,
            );
            if (!$this->save($provisioning, $next, $provisioningId, $holderId, $now)) {
                return;
            }
            $provisioning = $next;

            if ($provisioning->status() === AuthorizerProvisioningStatus::METADATA_TYPE_CONFLICT) {
                $this->jobs->complete($provisioningId, $holderId);
                return;
            }
        }

        if ($provisioning->status() === AuthorizerProvisioningStatus::METADATA_READY) {
            $ownership = $this->ownerships->current(
                $provisioning->componentPlatformId(),
                $provisioning->authorizerAppId(),
            );

            if (
                $ownership !== null
                && $provisioning->accountType() !== null
                && $ownership->accountType() !== $provisioning->accountType()
            ) {
                // Historical local ownership type is immutable. Surface the trusted provider mismatch
                // before reconnect, quota consumption, Account creation, or ownership movement.
                $next = $provisioning->withMetadata(
                    $ownership->accountType(),
                    $provisioning->metadataVersion() ?? 1,
                    $now,
                );
                if ($next->status() !== AuthorizerProvisioningStatus::METADATA_TYPE_CONFLICT) {
                    throw new \LogicException('Ownership type mismatch must produce metadata type conflict.');
                }
                if ($this->save($provisioning, $next, $provisioningId, $holderId, $now)) {
                    $this->jobs->complete($provisioningId, $holderId);
                }
                return;
            }

            $resolution = $ownership === null
                ? AuthorizerOwnershipResolution::UNOWNED
                : $this->ownershipResolver->resolve(
                    $provisioning->componentPlatformId(),
                    $provisioning->authorizerAppId(),
                    $provisioning->tenantId(),
                    $ownership->accountId(),
                );

            if ($resolution === AuthorizerOwnershipResolution::SAME_OWNER && $ownership !== null) {
                $this->connections->reconnect($ownership, $now);
                $next = $provisioning->reconnected($ownership->accountId(), $now);
                if ($this->save($provisioning, $next, $provisioningId, $holderId, $now)) {
                    $this->jobs->complete($provisioningId, $holderId);
                }
                return;
            }

            if ($resolution === AuthorizerOwnershipResolution::OTHER_OWNER) {
                $next = $provisioning->bindingConflict('binding_conflict', $now);
                if ($this->save($provisioning, $next, $provisioningId, $holderId, $now)) {
                    $this->jobs->complete($provisioningId, $holderId);
                }
                return;
            }

            if (!$this->task10Ready()) {
                // Backward-compatible Task 9 boundary: production wiring enables Task 10 explicitly.
                $this->jobs->release($provisioningId, $holderId, $now, null);
                return;
            }

            try {
                $provisioning = $this->quota->ensureConsumed($provisioning, $now);
            } catch (AppException $e) {
                if ($e->errorCode() === ErrorCode::FORBIDDEN) {
                    $latest = $this->provisionings->find($provisioningId) ?? $provisioning;
                    $next = $latest->quotaBlocked('quota_insufficient', $now);
                    if ($this->save($latest, $next, $provisioningId, $holderId, $now)) {
                        $this->jobs->complete($provisioningId, $holderId);
                    }
                    return;
                }
                $this->handleQuotaFailure($provisioning, $provisioningId, $holderId, $job->attemptCount(), $now, $e);
                return;
            } catch (Throwable $e) {
                $this->handleQuotaFailure($provisioning, $provisioningId, $holderId, $job->attemptCount(), $now, $e);
                return;
            }
        }

        if (in_array($provisioning->status(), [
            AuthorizerProvisioningStatus::QUOTA_CONSUMED,
            AuthorizerProvisioningStatus::PROVISION_FAILED,
        ], true)) {
            if (!$this->task10Ready()) {
                $this->jobs->release($provisioningId, $holderId, $now, null);
                return;
            }
            if ($provisioning->quotaReleaseEntryId() !== null) {
                $this->jobs->dead(
                    $provisioningId,
                    $holderId,
                    $provisioning->lastErrorCode() ?? AuthorizerProvisioningStatus::PROVISION_FAILED->value,
                );
                return;
            }

            $this->advanceFinalization(
                $provisioning,
                $metadataRecord,
                $provisioningId,
                $holderId,
                $job->attemptCount(),
                $now,
            );
            return;
        }

        $this->jobs->release($provisioningId, $holderId, $now, null);
    }

    private function advanceFinalization(
        AuthorizerProvisioning $provisioning,
        ?AuthorizerMetadataRecord $metadataRecord,
        string $provisioningId,
        string $holderId,
        int $attemptCount,
        DateTimeImmutable $now,
    ): void {
        $reconciledAccountId = $this->finalizer->reconcile($provisioning);
        if ($reconciledAccountId !== null) {
            $this->jobs->complete($provisioningId, $holderId);
            return;
        }

        $metadataRecord ??= $this->metadata?->current(
            $provisioning->componentPlatformId(),
            $provisioning->authorizerAppId(),
        );
        if ($metadataRecord === null) {
            $this->jobs->release(
                $provisioningId,
                $holderId,
                $now->modify('+' . $this->retryDelaySeconds($attemptCount) . ' seconds'),
                'METADATA_PROJECTION_MISSING',
            );
            return;
        }

        try {
            $this->finalizer->provision($provisioning, $metadataRecord, $now);
            $this->jobs->complete($provisioningId, $holderId);
        } catch (Throwable $e) {
            $this->handleFinalizationFailure(
                $provisioning,
                $provisioningId,
                $holderId,
                $attemptCount,
                $now,
                $e,
            );
        }
    }

    private function handleMetadataFailure(
        AuthorizerProvisioning $provisioning,
        string $provisioningId,
        string $holderId,
        int $attemptCount,
        DateTimeImmutable $now,
        Throwable $error,
    ): void {
        $errorCode = $this->errorCode($error);

        if (!$this->retryable($error) || $attemptCount >= self::MAX_AUTOMATIC_ATTEMPTS) {
            $next = $provisioning->metadataFailed($errorCode, $now);
            if ($this->save($provisioning, $next, $provisioningId, $holderId, $now)) {
                $this->jobs->dead($provisioningId, $holderId, $errorCode);
            }
            return;
        }

        $this->releaseRetry($provisioningId, $holderId, $attemptCount, $now, $errorCode);
    }

    private function handleQuotaFailure(
        AuthorizerProvisioning $provisioning,
        string $provisioningId,
        string $holderId,
        int $attemptCount,
        DateTimeImmutable $now,
        Throwable $error,
    ): void {
        $errorCode = $this->errorCode($error);
        if ($attemptCount >= self::MAX_AUTOMATIC_ATTEMPTS || !$this->quotaRetryable($error)) {
            $latest = $this->provisionings->find($provisioningId) ?? $provisioning;
            if ($latest->status() === AuthorizerProvisioningStatus::METADATA_READY) {
                $next = $latest->quotaBlocked($errorCode, $now);
                if ($this->save($latest, $next, $provisioningId, $holderId, $now)) {
                    $this->jobs->dead($provisioningId, $holderId, $errorCode);
                }
                return;
            }
        }

        $this->releaseRetry($provisioningId, $holderId, $attemptCount, $now, $errorCode);
    }

    private function handleFinalizationFailure(
        AuthorizerProvisioning $provisioning,
        string $provisioningId,
        string $holderId,
        int $attemptCount,
        DateTimeImmutable $now,
        Throwable $error,
    ): void {
        $errorCode = $this->errorCode($error);
        if ($this->retryable($error) && $attemptCount < self::MAX_AUTOMATIC_ATTEMPTS) {
            // Quota remains consumed; transient finalization failures are retried, never compensated here.
            $this->releaseRetry($provisioningId, $holderId, $attemptCount, $now, $errorCode);
            return;
        }

        // Reconcile durable Account/ownership/provider facts before any compensation decision.
        $latest = $this->provisionings->find($provisioningId) ?? $provisioning;
        $reconciledAccountId = $this->finalizer->reconcile($latest);
        if ($reconciledAccountId !== null) {
            $this->jobs->complete($provisioningId, $holderId);
            return;
        }

        if ($latest->status() === AuthorizerProvisioningStatus::QUOTA_CONSUMED) {
            $failed = $latest->provisionFailed($errorCode, $now);
            if (!$this->save($latest, $failed, $provisioningId, $holderId, $now)) {
                return;
            }
            $latest = $failed;
        }

        try {
            $this->quota->ensureReleased($latest, $now);
            $this->jobs->dead($provisioningId, $holderId, $errorCode);
        } catch (Throwable $releaseError) {
            $this->releaseRetry(
                $provisioningId,
                $holderId,
                $attemptCount,
                $now,
                $this->errorCode($releaseError),
            );
        }
    }

    private function save(
        AuthorizerProvisioning $current,
        AuthorizerProvisioning $next,
        string $provisioningId,
        string $holderId,
        DateTimeImmutable $now,
    ): bool {
        if ($this->provisionings->save($next, $current->version())) {
            return true;
        }

        $this->jobs->release(
            $provisioningId,
            $holderId,
            $now->modify('+60 seconds'),
            ErrorCode::CONFLICT->value,
        );
        return false;
    }

    private function releaseRetry(
        string $provisioningId,
        string $holderId,
        int $attemptCount,
        DateTimeImmutable $now,
        string $errorCode,
    ): void {
        $this->jobs->release(
            $provisioningId,
            $holderId,
            $now->modify('+' . $this->retryDelaySeconds($attemptCount) . ' seconds'),
            $errorCode,
        );
    }

    private function retryDelaySeconds(int $attemptCount): int
    {
        if ($attemptCount < 1) {
            return 60;
        }

        return min(1800, 60 * (2 ** min($attemptCount - 1, 5)));
    }

    private function retryable(Throwable $error): bool
    {
        if (!$error instanceof AppException) {
            return true;
        }

        return in_array($error->errorCode(), [
            ErrorCode::BAD_GATEWAY,
            ErrorCode::SERVICE_UNAVAILABLE,
            ErrorCode::INTERNAL_ERROR,
        ], true);
    }

    private function quotaRetryable(Throwable $error): bool
    {
        if (!$error instanceof AppException) {
            return true;
        }

        return in_array($error->errorCode(), [
            ErrorCode::CONFLICT,
            ErrorCode::BAD_GATEWAY,
            ErrorCode::SERVICE_UNAVAILABLE,
            ErrorCode::INTERNAL_ERROR,
        ], true);
    }

    private function errorCode(Throwable $error): string
    {
        return $error instanceof AppException
            ? $error->errorCode()->value
            : ErrorCode::INTERNAL_ERROR->value;
    }

    private function task10Ready(): bool
    {
        return $this->quota !== null && $this->finalizer !== null;
    }

    private function isTerminal(AuthorizerProvisioningStatus $status): bool
    {
        return in_array($status, [
            AuthorizerProvisioningStatus::PROVISIONED,
            AuthorizerProvisioningStatus::RECONNECTED,
            AuthorizerProvisioningStatus::QUOTA_BLOCKED,
            AuthorizerProvisioningStatus::BINDING_CONFLICT,
            AuthorizerProvisioningStatus::METADATA_FAILED,
            AuthorizerProvisioningStatus::METADATA_TYPE_CONFLICT,
            AuthorizerProvisioningStatus::AUTHORIZATION_INACTIVE,
        ], true);
    }
}
