<?php

declare(strict_types=1);

namespace app\openplatform\application;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\openplatform\contract\AuthorizerAuthorizationRepository;
use app\openplatform\contract\AuthorizerProvisioningRepository;
use app\openplatform\contract\ProvisioningJobScheduler;
use app\openplatform\domain\AuthorizerProvisioning;
use app\openplatform\domain\AuthorizerProvisioningStatus;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AuthorizerProvisioningRetryService
{
    public function __construct(
        private AuthorizerProvisioningRepository $provisionings,
        private AuthorizerAuthorizationRepository $authorizations,
        private ProvisioningJobScheduler $scheduler,
    ) {
    }

    public function retry(string $provisioningId, string $tenantId, DateTimeImmutable $now): AuthorizerProvisioning
    {
        $provisioningId = trim($provisioningId);
        $tenantId = trim($tenantId);
        if ($provisioningId === '' || $tenantId === '') {
            throw new InvalidArgumentException('Provisioning id and Tenant id must not be empty.');
        }

        $current = $this->provisionings->findForTenant($provisioningId, $tenantId);
        if ($current === null) {
            throw new AppException(ErrorCode::NOT_FOUND, 'Authorizer provisioning not found.', 404);
        }

        $authorization = $this->authorizations->current(
            $current->componentPlatformId(),
            $current->authorizerAppId(),
        );
        if ($authorization === null || !$authorization->isActive()) {
            throw new AppException(ErrorCode::CONFLICT, 'Authorizer authorization is not active.', 409);
        }

        if ($current->quotaReleaseEntryId() !== null) {
            throw new AppException(
                ErrorCode::CONFLICT,
                'Compensated provisioning cannot reuse its released quota consume operation.',
                409,
            );
        }

        if (in_array($current->status(), [
            AuthorizerProvisioningStatus::PROVISIONED,
            AuthorizerProvisioningStatus::RECONNECTED,
        ], true)) {
            throw new AppException(ErrorCode::CONFLICT, 'Completed authorizer provisioning is not retryable.', 409);
        }

        $next = $this->resumeCurrentStage($current, $now);
        if ($next !== $current && !$this->provisionings->save($next, $current->version())) {
            throw new AppException(ErrorCode::CONFLICT, 'Authorizer provisioning retry lost CAS ownership.', 409);
        }

        if (!$this->scheduler->requeue($provisioningId, $now)) {
            // A later retry can safely requeue an already-resumed durable stage.
            throw new AppException(ErrorCode::CONFLICT, 'Authorizer provisioning job is currently busy.', 409);
        }

        return $next;
    }

    private function resumeCurrentStage(AuthorizerProvisioning $current, DateTimeImmutable $now): AuthorizerProvisioning
    {
        if (in_array($current->status(), [
            AuthorizerProvisioningStatus::PENDING_METADATA,
            AuthorizerProvisioningStatus::METADATA_READY,
            AuthorizerProvisioningStatus::QUOTA_CONSUMED,
        ], true)) {
            return $current;
        }

        $status = match ($current->status()) {
            AuthorizerProvisioningStatus::METADATA_FAILED,
            AuthorizerProvisioningStatus::METADATA_TYPE_CONFLICT => AuthorizerProvisioningStatus::PENDING_METADATA,

            AuthorizerProvisioningStatus::QUOTA_BLOCKED,
            AuthorizerProvisioningStatus::BINDING_CONFLICT => AuthorizerProvisioningStatus::METADATA_READY,

            AuthorizerProvisioningStatus::PROVISION_FAILED => AuthorizerProvisioningStatus::QUOTA_CONSUMED,

            AuthorizerProvisioningStatus::AUTHORIZATION_INACTIVE => $current->quotaConsumeEntryId() !== null
                ? AuthorizerProvisioningStatus::QUOTA_CONSUMED
                : ($current->accountType() !== null
                    ? AuthorizerProvisioningStatus::METADATA_READY
                    : AuthorizerProvisioningStatus::PENDING_METADATA),

            default => throw new AppException(ErrorCode::CONFLICT, 'Authorizer provisioning state is not retryable.', 409),
        };

        if ($status === AuthorizerProvisioningStatus::QUOTA_CONSUMED && $current->quotaConsumeEntryId() === null) {
            throw new AppException(ErrorCode::CONFLICT, 'Quota-consumed retry stage has no durable consume reference.', 409);
        }
        if ($status === AuthorizerProvisioningStatus::METADATA_READY && $current->accountType() === null) {
            throw new AppException(ErrorCode::CONFLICT, 'Metadata-ready retry stage has no trusted Account type.', 409);
        }

        return AuthorizerProvisioning::reconstitute(
            $current->id(),
            $current->sourceIntentId(),
            $current->tenantId(),
            $current->componentPlatformId(),
            $current->authorizerAppId(),
            $current->accountType(),
            $status,
            $current->metadataVersion(),
            $current->quotaResourceKey(),
            $current->quotaConsumeEntryId(),
            $current->quotaReleaseEntryId(),
            $current->accountId(),
            null,
            null,
            $current->createdAt(),
            $now,
            null,
            $current->version() + 1,
        );
    }
}
