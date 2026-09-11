<?php

declare(strict_types=1);

namespace modules\miniapp\domain;

enum MiniAppConnectionMode: string
{
    case MANUAL = 'manual';
    case COMPONENT = 'component';
}
