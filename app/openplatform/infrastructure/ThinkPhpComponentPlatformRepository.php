<?php

declare(strict_types=1);

namespace app\openplatform\infrastructure;

use app\openplatform\contract\ComponentPlatformRepository;
use app\openplatform\domain\ComponentPlatform;
use think\facade\Db;

final class ThinkPhpComponentPlatformRepository implements ComponentPlatformRepository
{
    public function findById(string $componentPlatformId): ?ComponentPlatform
    {
        $row = Db::table('component_platforms')->where('id', $componentPlatformId)->find();
        if (!is_array($row)) {
            return null;
        }
        return new ComponentPlatform(
            (string) $row['id'],
            (string) $row['component_app_id'],
            (string) $row['app_secret_ref'],
            (string) $row['verify_token_ref'],
            (string) $row['encoding_aes_key_ref'],
            (bool) $row['enabled'],
        );
    }
}
