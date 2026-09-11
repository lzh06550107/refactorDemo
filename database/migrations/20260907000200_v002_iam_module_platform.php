<?php

declare(strict_types=1);

use app\common\migration\V1SqlMigration;

final class V002IamModulePlatform extends V1SqlMigration
{
    public function up(): void { $this->runBaseline('20260907_002_iam_module_platform_up.sql'); }
    public function down(): void { $this->runBaseline('20260907_002_iam_module_platform_down.sql'); }
}
