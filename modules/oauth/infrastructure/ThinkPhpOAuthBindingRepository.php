<?php

declare(strict_types=1);

namespace modules\oauth\infrastructure;

use modules\oauth\contract\OAuthBindingRepository;
use modules\oauth\domain\OAuthBinding;
use think\facade\Db;

final class ThinkPhpOAuthBindingRepository implements OAuthBindingRepository
{
    public function findEnabled(string $tenantId, string $businessAccountId, string $providerType): ?OAuthBinding
    {
        $row = Db::table('oauth_bindings')->where([
            'tenant_id' => $tenantId,
            'business_account_id' => $businessAccountId,
            'provider_type' => $providerType,
            'enabled' => 1,
        ])->find();

        if ($row === null) {
            return null;
        }
        $row = (array) $row;

        return new OAuthBinding(
            (string) $row['id'],
            (string) $row['tenant_id'],
            (string) $row['business_account_id'],
            (string) $row['provider_type'],
            (string) $row['oauth_provider_account_id'],
            (bool) $row['enabled'],
        );
    }
}
