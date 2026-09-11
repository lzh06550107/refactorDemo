<?php

declare(strict_types=1);

namespace modules\iam\domain;

enum AdminUserStatus: string
{
    case ACTIVE = 'active';
    case BANNED = 'banned';
}
