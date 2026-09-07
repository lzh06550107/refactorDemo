<?php

declare(strict_types=1);

namespace app\iam\domain;

enum AdminUserStatus: string
{
    case ACTIVE = 'active';
    case BANNED = 'banned';
}
