<?php

declare(strict_types=1);

namespace app\openplatform\infrastructure;

use app\openplatform\application\OpenPlatformAudit;
use app\openplatform\contract\AuthorizerAccountFinalizer;
use app\openplatform\domain\AuthorizerMetadataRecord;
use app\openplatform\domain\AuthorizerProvisioning;
use DateTimeImmutable;

final readonly class AuditedAuthorizerAccountFinalizer implements AuthorizerAccountFinalizer
{
    public function __construct(
        private AuthorizerAccountFinalizer $inner,
        private OpenPlatformAudit $audit,
    ) {
    }

    public function provision(
        AuthorizerProvisioning $provisioning,
        AuthorizerMetadataRecord $metadata,
        DateTimeImmutable $now,
    ): string {
        $accountId = $this->inner->provision($provisioning, $metadata, $now);
        $this->emit($provisioning, $accountId, $now);
        return $accountId;
    }

    public function reconcile(AuthorizerProvisioning $provisioning): ?string
    {
        $accountId = $this->inner->reconcile($provisioning);
        if ($accountId !== null) {
            $this->emit($provisioning, $accountId, $provisioning->updatedAt());
        }
        return $accountId;
    }

    private function emit(AuthorizerProvisioning $provisioning, string $accountId, DateTimeImmutable $now): void
    {
        $this->audit->system(
            $provisioning->tenantId(),
            $accountId,
            OpenPlatformAudit::PROVISIONING_PROVISIONED,
            $provisioning->id(),
            [
                'provisioning_id' => $provisioning->id(),
                'component_platform_id' => $provisioning->componentPlatformId(),
                'authorizer_app_id' => $provisioning->authorizerAppId(),
                'metadata_version' => $provisioning->metadataVersion(),
                'account_type' => $provisioning->accountType()?->value,
            ],
            $now,
        );
    }
}
