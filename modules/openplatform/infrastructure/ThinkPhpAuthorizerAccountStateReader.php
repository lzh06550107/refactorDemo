<?php

declare(strict_types=1);

namespace modules\openplatform\infrastructure;

use modules\account\domain\AccountStatus;
use modules\openplatform\contract\AuthorizerAccountStateReader;
use modules\openplatform\domain\AuthorizerAccountOwnership;
use think\facade\Db;

final readonly class ThinkPhpAuthorizerAccountStateReader implements AuthorizerAccountStateReader
{
    public function status(AuthorizerAccountOwnership $ownership): ?AccountStatus
    {
        $row = Db::table('accounts')->where([
            'id' => $ownership->accountId(),
            'tenant_id' => $ownership->tenantId(),
            'type' => $ownership->accountType()->value,
        ])->find();
        if (!is_array($row)) {
            return null;
        }

        return AccountStatus::tryFrom((string) ($row['status'] ?? ''));
    }
}
