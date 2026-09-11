<?php

declare(strict_types=1);

namespace modules\openplatform\domain;

enum AuthorizationIntentMode: string
{
    case BIND_EXISTING_ACCOUNT = 'bind_existing_account';
    case AUTO_PROVISION_ACCOUNT = 'auto_provision_account';
}
