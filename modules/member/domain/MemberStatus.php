<?php

declare(strict_types=1);

namespace modules\member\domain;

enum MemberStatus: string
{
    case ACTIVE = 'active';
    case DISABLED = 'disabled';
}
