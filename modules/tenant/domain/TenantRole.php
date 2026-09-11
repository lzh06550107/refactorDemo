<?php

declare(strict_types=1);

namespace modules\tenant\domain;

enum TenantRole: string
{
    case OWNER = 'owner';
    case ADMIN = 'admin';
    case MEMBER = 'member';
}
