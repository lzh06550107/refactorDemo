<?php

declare(strict_types=1);

use app\common\migration\V1SqlMigration;

final class V007OpenplatformComponentTrust extends V1SqlMigration
{
    public function up(): void { $this->runBaseline('20260908_007_openplatform_component_trust_up.sql'); }
    public function down(): void { $this->runBaseline('20260908_007_openplatform_component_trust_down.sql'); }
}
