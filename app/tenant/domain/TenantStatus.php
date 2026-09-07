<?php

declare(strict_types=1);

namespace app\tenant\domain;

enum TenantStatus: string
{
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';
}
