<?php

declare(strict_types=1);

use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\site\application\SiteDomainResolver;
use modules\site\contract\DomainBindingRepository;
use modules\site\contract\SiteRepository;
use modules\site\domain\DomainBinding;
use modules\site\domain\DomainBindingSource;
use modules\site\domain\DomainName;
use modules\site\domain\Site;
use modules\site\domain\SiteStatus;

$binding = new DomainBinding('bind-1', 'tenant-1', 'account-1', 'site-1', DomainName::fromHostOrUrl('site.example.com'), DomainBindingSource::R20_SITE_MULTI, 5, null);
$site = new Site('site-1', 'tenant-1', 'account-1', 'Main', SiteStatus::ENABLED, true, null, 5);

$bindingRepo = new class($binding) implements DomainBindingRepository {
    public function __construct(private ?DomainBinding $binding) {}
    public function findByHost(DomainName $host): ?DomainBinding { return $this->binding !== null && $this->binding->host()->value() === $host->value() ? $this->binding : null; }
};
$siteRepo = new class($site) implements SiteRepository {
    public function __construct(private ?Site $site) {}
    public function findById(string $tenantId, string $siteId): ?Site { return $this->site !== null && $this->site->tenantId() === $tenantId && $this->site->id() === $siteId ? $this->site : null; }
};
$resolver = new SiteDomainResolver($bindingRepo, $siteRepo);
$resolved = $resolver->resolve('SITE.EXAMPLE.COM');
expectSame('site-1', $resolved->site()->id(), 'host should resolve to site');
expectSame('bind-1', $resolved->binding()->id(), 'host should resolve through its binding');

$crossTenantBinding = new DomainBinding('bind-x', 'tenant-2', 'account-1', 'site-1', DomainName::fromHostOrUrl('cross.example.com'), DomainBindingSource::NATIVE);
$crossResolver = new SiteDomainResolver(
    new class($crossTenantBinding) implements DomainBindingRepository {
        public function __construct(private DomainBinding $binding) {}
        public function findByHost(DomainName $host): ?DomainBinding { return $this->binding; }
    },
    new class($site) implements SiteRepository {
        public function __construct(private Site $site) {}
        public function findById(string $tenantId, string $siteId): ?Site { return $this->site; }
    },
);
try {
    $crossResolver->resolve('cross.example.com');
    throw new RuntimeException('cross-tenant binding must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::CONFLICT, $e->errorCode(), 'cross-tenant binding must be a stable conflict');
}
