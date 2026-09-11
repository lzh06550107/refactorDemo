<?php

declare(strict_types=1);

use app\common\migration\V1SqlMigration;

final class V001IamTenantAccount extends V1SqlMigration
{
    public function up(): void { $this->runBaseline('20260907_001_iam_tenant_account_up.sql'); }
    public function down(): void { $this->runBaseline('20260907_001_iam_tenant_account_down.sql'); }
}
