<?php

declare(strict_types=1);

namespace app\module\domain;

enum ModuleBindingType: string
{
    case RULE = 'rule';
    case COVER = 'cover';
    case MENU = 'menu';
    case HOME = 'home';
    case PROFILE = 'profile';
    case SHORTCUT = 'shortcut';
    case FUNCTION = 'function';
    case MINE = 'mine';
    case SYSTEM_WELCOME = 'system_welcome';
    case PAGE = 'page';
    case WEBAPP = 'webapp';
    case PHONEAPP = 'phoneapp';
}
