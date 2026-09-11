<?php

declare(strict_types=1);

use app\common\migration\V1SqlMigration;

final class V009OpenplatformAuthorizerProvisioning extends V1SqlMigration
{
    public function up(): void { $this->runBaseline('20260909_009_openplatform_authorizer_provisioning_up.sql'); }
    public function down(): void { $this->runBaseline('20260909_009_openplatform_authorizer_provisioning_down.sql'); }
}
