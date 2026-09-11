<?php

declare(strict_types=1);

namespace modules\miniapp\contract;

use modules\miniapp\domain\MiniAppCodeSession;
use modules\miniapp\domain\MiniAppProviderAccount;

interface MiniAppCodeExchangeClient
{
    public function exchange(MiniAppProviderAccount $provider, string $code): MiniAppCodeSession;
}
