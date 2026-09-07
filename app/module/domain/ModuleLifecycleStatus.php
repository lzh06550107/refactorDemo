<?php

declare(strict_types=1);

namespace app\module\domain;

enum ModuleLifecycleStatus: string
{
    case ACTIVE = 'active';
    case RECYCLED = 'recycled';
}
