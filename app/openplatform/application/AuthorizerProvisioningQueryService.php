<?php

declare(strict_types=1);

namespace app\openplatform\application;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\openplatform\contract\AuthorizerProvisioningRepository;
use app\openplatform\domain\AuthorizerProvisioning;
use InvalidArgumentException;

final readonly class AuthorizerProvisioningQueryService
{
    public function __construct(private AuthorizerProvisioningRepository $provisionings)
    {
    }

    public function get(string $provisioningId, string $tenantId): AuthorizerProvisioning
    {
        $provisioningId = trim($provisioningId);
        $tenantId = trim($tenantId);
        if ($provisioningId === '' || $tenantId === '') {
            throw new InvalidArgumentException('Provisioning id and Tenant id must not be empty.');
        }

        $provisioning = $this->provisionings->findForTenant($provisioningId, $tenantId);
        if ($provisioning === null) {
            throw new AppException(ErrorCode::NOT_FOUND, 'Authorizer provisioning not found.', 404);
        }
        return $provisioning;
    }
}
