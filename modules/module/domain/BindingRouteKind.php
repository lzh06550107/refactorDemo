<?php

declare(strict_types=1);

namespace modules\module\domain;

enum BindingRouteKind: string
{
    case NEW_RUNTIME = 'new_runtime';
    case LEGACY_DELEGATE = 'legacy_delegate';
}
