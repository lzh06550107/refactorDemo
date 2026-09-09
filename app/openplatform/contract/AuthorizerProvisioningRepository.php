<?php

declare(strict_types=1);

namespace app\openplatform\contract;

use app\openplatform\domain\AuthorizerProvisioning;

interface AuthorizerProvisioningRepository
{
    public function insert(AuthorizerProvisioning $provisioning): void;

    public function find(string $id): ?AuthorizerProvisioning;

    public function findForTenant(string $id, string $tenantId): ?AuthorizerProvisioning;

    public function findBySourceIntent(string $sourceIntentId): ?AuthorizerProvisioning;

    public function save(AuthorizerProvisioning $next, int $expectedVersion): bool;
}
