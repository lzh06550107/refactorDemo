<?php

declare(strict_types=1);

namespace modules\site\domain;

enum SiteStatus: string
{
    case ENABLED = 'enabled';
    case DISABLED = 'disabled';
}
