<?php

declare(strict_types=1);

namespace modules\openplatform\infrastructure;

use modules\openplatform\contract\AuthorizerTenantScopeReader;
use think\facade\Db;

final readonly class ThinkPhpAuthorizerTenantScopeReader implements AuthorizerTenantScopeReader
{
    public function allowsTenant(
        string $tenantId,
        string $componentPlatformId,
        string $authorizerAppId,
    ): bool {
        $ownership = Db::table('authorizer_account_ownerships')->where([
            'component_platform_id' => $componentPlatformId,
            'authorizer_app_id' => $authorizerAppId,
        ])->find();
        if (is_array($ownership)) {
            return hash_equals((string) ($ownership['tenant_id'] ?? ''), $tenantId);
        }

        $terminalStatuses = [
            'provisioned',
            'reconnected',
            'quota_blocked',
            'binding_conflict',
            'metadata_failed',
            'metadata_type_conflict',
            'authorization_inactive',
        ];
        $provisioning = Db::table('authorizer_provisionings')->where([
            'tenant_id' => $tenantId,
            'component_platform_id' => $componentPlatformId,
            'authorizer_app_id' => $authorizerAppId,
        ])->whereNotIn('status', $terminalStatuses)->find();

        return is_array($provisioning);
    }
}
