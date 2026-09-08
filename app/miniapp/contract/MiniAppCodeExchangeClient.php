<?php

declare(strict_types=1);

namespace app\miniapp\contract;

use app\miniapp\domain\MiniAppCodeSession;
use app\miniapp\domain\MiniAppProviderAccount;

interface MiniAppCodeExchangeClient
{
    public function exchange(MiniAppProviderAccount $provider, string $code): MiniAppCodeSession;
}
