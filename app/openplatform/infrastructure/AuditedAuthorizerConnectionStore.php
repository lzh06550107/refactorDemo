<?php

declare(strict_types=1);

namespace app\openplatform\infrastructure;

use app\openplatform\application\OpenPlatformAudit;
use app\openplatform\contract\AuthorizerConnectionStore;
use app\openplatform\domain\AuthorizerAccountOwnership;
use DateTimeImmutable;

final readonly class AuditedAuthorizerConnectionStore implements AuthorizerConnectionStore
{
    public function __construct(
        private AuthorizerConnectionStore $inner,
        private OpenPlatformAudit $audit,
    ) {
    }

    public function enableExisting(AuthorizerAccountOwnership $ownership, DateTimeImmutable $now): void
    {
        $this->inner->enableExisting($ownership, $now);
    }

    public function disable(string $componentPlatformId, string $authorizerAppId, DateTimeImmutable $now): void
    {
        $this->inner->disable($componentPlatformId, $authorizerAppId, $now);
        $operationId = $componentPlatformId . ':' . $authorizerAppId . ':disconnect';
        $this->audit->provider(
            null,
            null,
            OpenPlatformAudit::CONNECTION_DISCONNECTED,
            'provider:' . $operationId,
            'provider:' . $operationId,
            [
                'component_platform_id' => $componentPlatformId,
                'authorizer_app_id' => $authorizerAppId,
            ],
            $now,
        );
    }
}
