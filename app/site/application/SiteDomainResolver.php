<?php

declare(strict_types=1);

namespace app\site\application;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\site\contract\DomainBindingRepository;
use app\site\contract\SiteRepository;
use app\site\domain\DomainName;
use app\site\domain\ResolvedSite;

final readonly class SiteDomainResolver
{
    public function __construct(private DomainBindingRepository $bindings, private SiteRepository $sites) {}

    public function resolve(string $host): ResolvedSite
    {
        $domain = DomainName::fromHostOrUrl($host);
        $binding = $this->bindings->findByHost($domain);
        if ($binding === null) {
            throw new AppException(ErrorCode::NOT_FOUND, 'Domain binding not found.', 404);
        }
        $site = $this->sites->findById($binding->tenantId(), $binding->siteId());
        if ($site === null) {
            throw new AppException(ErrorCode::NOT_FOUND, 'Site not found.', 404);
        }
        if ($site->tenantId() !== $binding->tenantId() || $site->accountId() !== $binding->accountId()) {
            throw new AppException(ErrorCode::CONFLICT, 'Domain binding crosses tenant/account boundary.', 409);
        }
        if (!$site->isEnabled()) {
            throw new AppException(ErrorCode::FORBIDDEN, 'Site is disabled.', 403);
        }
        return new ResolvedSite($site, $binding);
    }
}
