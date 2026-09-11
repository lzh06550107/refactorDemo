<?php

declare(strict_types=1);

namespace modules\site\domain;

final readonly class ResolvedSite
{
    public function __construct(private Site $site, private DomainBinding $binding) {}
    public function site(): Site { return $this->site; }
    public function binding(): DomainBinding { return $this->binding; }
}
