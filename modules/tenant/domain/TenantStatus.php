<?php

declare(strict_types=1);

namespace modules\tenant\domain;

enum TenantStatus: string
{
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';
}
