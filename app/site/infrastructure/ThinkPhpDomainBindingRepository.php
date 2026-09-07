<?php

declare(strict_types=1);

namespace app\site\infrastructure;

use app\site\contract\DomainBindingRepository;
use app\site\domain\DomainBinding;
use app\site\domain\DomainBindingSource;
use app\site\domain\DomainName;
use think\facade\Db;

final class ThinkPhpDomainBindingRepository implements DomainBindingRepository
{
    public function findByHost(DomainName $host): ?DomainBinding
    {
        $row = Db::table('domain_bindings')->where('host', $host->value())->find();
        if ($row === null) {
            return null;
        }
        $row = (array) $row;
        return new DomainBinding(
            (string) $row['id'],
            (string) $row['tenant_id'],
            (string) $row['account_id'],
            (string) $row['site_id'],
            DomainName::fromHostOrUrl((string) $row['host']),
            DomainBindingSource::from((string) $row['source']),
            $row['legacy_multi_id'] === null ? null : (int) $row['legacy_multi_id'],
            $row['default_module'] === null ? null : (string) $row['default_module'],
        );
    }
}
