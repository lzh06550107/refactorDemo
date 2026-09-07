<?php

declare(strict_types=1);

namespace app\site\contract;

use app\site\domain\DomainBinding;
use app\site\domain\DomainName;

interface DomainBindingRepository
{
    public function findByHost(DomainName $host): ?DomainBinding;
}
