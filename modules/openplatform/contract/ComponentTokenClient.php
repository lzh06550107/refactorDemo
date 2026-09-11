<?php

declare(strict_types=1);

namespace modules\openplatform\contract;

use modules\openplatform\domain\ComponentPlatform;
use modules\openplatform\domain\ComponentTokenResponse;

interface ComponentTokenClient
{
    public function refresh(ComponentPlatform $platform, string $appSecret, string $verifyTicket): ComponentTokenResponse;
}
