<?php

declare(strict_types=1);

namespace modules\site\contract;

use modules\site\domain\DomainBinding;
use modules\site\domain\DomainName;

interface DomainBindingRepository
{
    public function findByHost(DomainName $host): ?DomainBinding;
}
