<?php

declare(strict_types=1);

namespace app\site\compat;

use app\site\domain\DomainBinding;
use app\site\domain\DomainBindingSource;
use app\site\domain\DomainName;
use app\site\domain\SiteStatus;
use InvalidArgumentException;

final class R20SiteSnapshotMapper
{
    /** @param array<string,mixed> $row */
    public function fromSiteMulti(array $row, string $tenantId, string $accountId, int $defaultLegacySiteId): LegacySiteSnapshot
    {
        foreach (['id','uniacid','title','styleid','status'] as $key) {
            if (!array_key_exists($key, $row)) {
                throw new InvalidArgumentException('R20 site_multi row missing ' . $key . '.');
            }
        }
        $multiId = (int) $row['id'];
        $isDefault = $multiId === $defaultLegacySiteId;
        $bindHost = trim((string) ($row['bindhost'] ?? ''));
        return new LegacySiteSnapshot(
            $tenantId,
            $accountId,
            (int) $row['uniacid'],
            $multiId,
            (int) $row['styleid'],
            (string) $row['title'],
            $isDefault || (int) $row['status'] === 1 ? SiteStatus::ENABLED : SiteStatus::DISABLED,
            $isDefault,
            $bindHost === '' ? null : DomainName::fromHostOrUrl($bindHost),
        );
    }

    /** @param array<string,mixed> $row */
    public function fromAccountBindDomain(array $row, string $tenantId, string $accountId, string $siteId): DomainBinding
    {
        foreach (['uniacid','bind_domain','default_module'] as $key) {
            if (!array_key_exists($key, $row)) {
                throw new InvalidArgumentException('R20 uni_settings row missing ' . $key . '.');
            }
        }
        $module = trim((string) $row['default_module']);
        if ($module === '') {
            throw new InvalidArgumentException('R20 account bind domain requires default_module.');
        }
        return new DomainBinding(
            'legacy-account-domain-' . (int) $row['uniacid'],
            $tenantId,
            $accountId,
            $siteId,
            DomainName::fromHostOrUrl((string) $row['bind_domain']),
            DomainBindingSource::R20_ACCOUNT_BIND_DOMAIN,
            null,
            $module,
        );
    }
}
