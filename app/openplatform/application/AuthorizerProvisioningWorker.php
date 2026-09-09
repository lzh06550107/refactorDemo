<?php

declare(strict_types=1);

namespace app\openplatform\application;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\openplatform\contract\AuthorizerAuthorizationRepository;
use app\openplatform\contract\AuthorizerOwnershipRepository;
use app\openplatform\contract\AuthorizerProvisioningRepository;
use app\openplatform\contract\ProvisioningJobRepository;
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

        if (in_array($provisioning->status(), [
            AuthorizerProvisioningStatus::PENDING_METADATA,
            AuthorizerProvisioningStatus::METADATA_FAILED,
        ], true)) {
            try {
                $metadata = $this->metadataSync->sync(
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
                $metadata->accountType(),
                $metadata->version(),
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

        if ($provisioning->status() !== AuthorizerProvisioningStatus::METADATA_READY) {
            if ($this->isTerminal($provisioning->status())) {
                $this->jobs->complete($provisioningId, $holderId);
                return;
            }

            // Task 10 owns quota/finalization stages. Keep this job immediately eligible.
            $this->jobs->release($provisioningId, $holderId, $now, null);
            return;
        }

        $ownership = $this->ownerships->current(
            $provisioning->componentPlatformId(),
            $provisioning->authorizerAppId(),
        );
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

        // Unowned authorizers continue immediately into Task 10 quota/finalization work.
        $this->jobs->release($provisioningId, $holderId, $now, null);
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

        $delaySeconds = $this->retryDelaySeconds($attemptCount);
        $this->jobs->release(
            $provisioningId,
            $holderId,
            $now->modify('+' . $delaySeconds . ' seconds'),
            $errorCode,
        );
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

    private function errorCode(Throwable $error): string
    {
        return $error instanceof AppException
            ? $error->errorCode()->value
            : ErrorCode::INTERNAL_ERROR->value;
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
