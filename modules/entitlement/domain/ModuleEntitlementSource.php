<?php

declare(strict_types=1);

namespace modules\entitlement\domain;

enum ModuleEntitlementSource: string
{
    case PLATFORM_GRANT = 'platform_grant';
    case PACKAGE = 'package';
    case PURCHASE = 'purchase';
    case MANUAL_GRANT = 'manual_grant';
    case TRIAL = 'trial';
    case MANUAL_LEGACY = 'manual_legacy';
}
