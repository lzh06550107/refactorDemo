<?php

declare(strict_types=1);

namespace app\site\domain;

enum SiteStatus: string
{
    case ENABLED = 'enabled';
    case DISABLED = 'disabled';
}
