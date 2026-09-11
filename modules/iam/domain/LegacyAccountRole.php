<?php

declare(strict_types=1);

namespace modules\iam\domain;

enum LegacyAccountRole: string
{
    case NONE = '';
    case FOUNDER = 'founder';
    case VICE_FOUNDER = 'vice_founder';
    case OWNER = 'owner';
    case MANAGER = 'manager';
    case OPERATOR = 'operator';
    case CLERK = 'clerk';
    case EXPIRED = 'expired';
    case UNBOUND_USER = 'unbind_user';
}
