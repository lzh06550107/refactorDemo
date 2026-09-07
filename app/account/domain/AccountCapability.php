<?php

declare(strict_types=1);

namespace app\account\domain;

enum AccountCapability: string
{
    case MODULE_RUNTIME = 'module_runtime';
    case VERSIONED_RELEASE = 'versioned_release';
}
