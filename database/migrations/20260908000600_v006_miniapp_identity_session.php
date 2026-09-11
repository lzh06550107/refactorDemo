<?php

declare(strict_types=1);

use app\common\migration\V1SqlMigration;

final class V006MiniappIdentitySession extends V1SqlMigration
{
    public function up(): void { $this->runBaseline('20260908_006_miniapp_identity_session_up.sql'); }
    public function down(): void { $this->runBaseline('20260908_006_miniapp_identity_session_down.sql'); }
}
