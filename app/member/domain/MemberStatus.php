<?php

declare(strict_types=1);

namespace app\member\domain;

enum MemberStatus: string
{
    case ACTIVE = 'active';
    case DISABLED = 'disabled';
}
