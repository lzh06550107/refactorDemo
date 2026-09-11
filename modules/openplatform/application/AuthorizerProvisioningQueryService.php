<?php

declare(strict_types=1);

namespace modules\openplatform\application;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\openplatform\contract\AuthorizerProvisioningRepository;
use modules\openplatform\domain\AuthorizerProvisioning;
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
