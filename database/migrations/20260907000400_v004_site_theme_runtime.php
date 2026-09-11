<?php

declare(strict_types=1);

use app\common\migration\V1SqlMigration;

final class V004SiteThemeRuntime extends V1SqlMigration
{
    public function up(): void { $this->runBaseline('20260907_004_site_theme_runtime_up.sql'); }
    public function down(): void { $this->runBaseline('20260907_004_site_theme_runtime_down.sql'); }
}
