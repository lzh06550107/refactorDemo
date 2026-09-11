<?php

declare(strict_types=1);

namespace modules\openplatform\application;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\openplatform\contract\AuthorizerTenantScopeReader;
use modules\openplatform\domain\AuthorizerMetadataRecord;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AuthorizerMetadataRefreshService
{
    public function __construct(
        private AuthorizerTenantScopeReader $scope,
        private AuthorizerMetadataSyncService $metadataSync,
    ) {
    }

    public function refresh(
        string $tenantId,
        string $componentPlatformId,
        string $authorizerAppId,
        DateTimeImmutable $now,
    ): AuthorizerMetadataRecord {
        $tenantId = trim($tenantId);
        $componentPlatformId = trim($componentPlatformId);
        $authorizerAppId = trim($authorizerAppId);
        if ($tenantId === '' || $componentPlatformId === '' || $authorizerAppId === '') {
            throw new InvalidArgumentException('Tenant, component platform and authorizer identifiers must not be empty.');
        }

        if (!$this->scope->allowsTenant($tenantId, $componentPlatformId, $authorizerAppId)) {
            throw new AppException(ErrorCode::NOT_FOUND, 'OpenPlatform authorizer not found in current Tenant scope.', 404);
        }

        return $this->metadataSync->sync(
            $componentPlatformId,
            $authorizerAppId,
            $now,
            'admin_metadata_refresh',
        );
    }
}
