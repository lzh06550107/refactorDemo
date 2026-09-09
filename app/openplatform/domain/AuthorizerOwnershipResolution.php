<?php

declare(strict_types=1);

namespace app\openplatform\domain;

enum AuthorizerOwnershipResolution: string
{
    case UNOWNED = 'unowned';
    case SAME_OWNER = 'same_owner';
    case OTHER_OWNER = 'other_owner';
}
