<?php

declare(strict_types=1);

namespace app\openplatform\contract;

use app\openplatform\domain\ComponentPlatform;
use app\openplatform\domain\ComponentTokenResponse;

interface ComponentTokenClient
{
    public function refresh(ComponentPlatform $platform, string $appSecret, string $verifyTicket): ComponentTokenResponse;
}
