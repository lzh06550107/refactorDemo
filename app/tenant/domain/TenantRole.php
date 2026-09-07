<?php

declare(strict_types=1);

namespace app\tenant\domain;

enum TenantRole: string
{
    case OWNER = 'owner';
    case ADMIN = 'admin';
    case MEMBER = 'member';
}
