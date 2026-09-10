<?php

declare(strict_types=1);

namespace app\openplatform\infrastructure;

use app\openplatform\application\OpenPlatformAudit;
use app\openplatform\contract\AuthorizerProvisioningRepository;
use app\openplatform\domain\AuthorizerProvisioning;
use app\openplatform\domain\AuthorizerProvisioningStatus;

final readonly class AuditedAuthorizerProvisioningRepository implements AuthorizerProvisioningRepository
{
    public function __construct(
        private AuthorizerProvisioningRepository $inner,
        private OpenPlatformAudit $audit,
    ) {
    }

    public function insert(AuthorizerProvisioning $provisioning): void
    {
        $this->inner->insert($provisioning);
    }

    public function find(string $id): ?AuthorizerProvisioning
    {
        return $this->inner->find($id);
    }

    public function findForTenant(string $id, string $tenantId): ?AuthorizerProvisioning
    {
        return $this->inner->findForTenant($id, $tenantId);
    }

    public function findBySourceIntent(string $sourceIntentId): ?AuthorizerProvisioning
    {
        return $this->inner->findBySourceIntent($sourceIntentId);
    }

    public function save(AuthorizerProvisioning $next, int $expectedVersion): bool
    {
        $current = $this->inner->find($next->id());
        $saved = $this->inner->save($next, $expectedVersion);
        if (!$saved) {
            return false;
        }

        $emitted = [];
        if ($current?->quotaConsumeEntryId() === null && $next->quotaConsumeEntryId() !== null) {
            $this->emit($next, OpenPlatformAudit::PROVISIONING_QUOTA_CONSUMED, [
                'quota_ledger_entry_id' => $next->quotaConsumeEntryId(),
                'quota_resource_key' => $next->quotaResourceKey(),
            ]);
            $emitted[OpenPlatformAudit::PROVISIONING_QUOTA_CONSUMED] = true;
        }

        if ($current?->quotaReleaseEntryId() === null && $next->quotaReleaseEntryId() !== null) {
            $this->emit($next, OpenPlatformAudit::PROVISIONING_QUOTA_RELEASED, [
                'quota_ledger_entry_id' => $next->quotaReleaseEntryId(),
                'quota_resource_key' => $next->quotaResourceKey(),
            ]);
            $emitted[OpenPlatformAudit::PROVISIONING_QUOTA_RELEASED] = true;
        }

        $action = match ($next->status()) {
            AuthorizerProvisioningStatus::METADATA_READY => OpenPlatformAudit::PROVISIONING_METADATA_READY,
            AuthorizerProvisioningStatus::QUOTA_CONSUMED => OpenPlatformAudit::PROVISIONING_QUOTA_CONSUMED,
            AuthorizerProvisioningStatus::PROVISIONED => OpenPlatformAudit::PROVISIONING_PROVISIONED,
            AuthorizerProvisioningStatus::RECONNECTED => OpenPlatformAudit::PROVISIONING_RECONNECTED,
            AuthorizerProvisioningStatus::BINDING_CONFLICT => OpenPlatformAudit::PROVISIONING_BINDING_CONFLICT,
            AuthorizerProvisioningStatus::METADATA_FAILED,
            AuthorizerProvisioningStatus::METADATA_TYPE_CONFLICT,
            AuthorizerProvisioningStatus::PROVISION_FAILED,
            AuthorizerProvisioningStatus::QUOTA_BLOCKED,
            AuthorizerProvisioningStatus::AUTHORIZATION_INACTIVE => OpenPlatformAudit::PROVISIONING_FAILED,
            default => null,
        };

        if (
            $action !== null
            && !isset($emitted[$action])
            && ($current === null || $current->status() !== $next->status())
        ) {
            $this->emit($next, $action);
        }

        return true;
    }

    /** @param array<string,mixed> $extra */
    private function emit(AuthorizerProvisioning $provisioning, string $action, array $extra = []): void
    {
        $this->audit->system(
            $provisioning->tenantId(),
            $provisioning->accountId(),
            $action,
            $provisioning->id(),
            [
                'provisioning_id' => $provisioning->id(),
                'component_platform_id' => $provisioning->componentPlatformId(),
                'authorizer_app_id' => $provisioning->authorizerAppId(),
                'metadata_version' => $provisioning->metadataVersion(),
                'account_type' => $provisioning->accountType()?->value,
                'error_code' => $provisioning->lastErrorCode(),
                'error_stage' => $provisioning->lastErrorStage(),
            ] + $extra,
            $provisioning->updatedAt(),
        );
    }
}
