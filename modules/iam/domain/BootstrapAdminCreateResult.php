<?php

declare(strict_types=1);

namespace modules\iam\domain;

enum BootstrapAdminCreateResult
{
    case CREATED;
    case ALREADY_EXISTS;
}
