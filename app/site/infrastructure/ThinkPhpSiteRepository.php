<?php

declare(strict_types=1);

namespace app\site\infrastructure;

use app\site\contract\SiteRepository;
use app\site\domain\Site;
use app\site\domain\SiteStatus;
use think\facade\Db;

final class ThinkPhpSiteRepository implements SiteRepository
{
    public function findById(string $tenantId, string $siteId): ?Site
    {
        $row = Db::table('sites')->where(['tenant_id' => $tenantId, 'id' => $siteId])->find();
        if ($row === null) {
            return null;
        }
        $row = (array) $row;
        return new Site(
            (string) $row['id'],
            (string) $row['tenant_id'],
            (string) $row['account_id'],
            (string) $row['name'],
            SiteStatus::from((string) $row['status']),
            (bool) $row['is_default'],
            $row['active_theme_release_id'] === null ? null : (string) $row['active_theme_release_id'],
            $row['legacy_multi_id'] === null ? null : (int) $row['legacy_multi_id'],
        );
    }
}
