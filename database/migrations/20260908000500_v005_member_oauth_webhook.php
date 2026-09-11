<?php

declare(strict_types=1);

use app\common\migration\V1SqlMigration;

final class V005MemberOauthWebhook extends V1SqlMigration
{
    public function up(): void { $this->runBaseline('20260908_005_member_oauth_webhook_up.sql'); }
    public function down(): void { $this->runBaseline('20260908_005_member_oauth_webhook_down.sql'); }
}
