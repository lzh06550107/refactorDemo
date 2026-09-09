<?php

declare(strict_types=1);

namespace app\openplatform\domain;

enum OpenPlatformPermission: string
{
    case READ = 'openplatform.authorizer.read';
    case START = 'openplatform.authorizer.start';
    case BIND = 'openplatform.authorizer.bind';
    case PROVISION = 'openplatform.authorizer.provision';
    case REFRESH_METADATA = 'openplatform.authorizer.refresh_metadata';
    case RETRY_PROVISION = 'openplatform.authorizer.retry_provision';
}
