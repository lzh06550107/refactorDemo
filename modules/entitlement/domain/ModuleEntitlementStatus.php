<?php

declare(strict_types=1);

namespace modules\entitlement\domain;

enum ModuleEntitlementStatus: string
{
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';
    case EXPIRED = 'expired';
    case REVOKED = 'revoked';
}
