<?php

declare(strict_types=1);

namespace app\common\context;

enum RuntimeType: string
{
    case ADMIN = 'admin';
    case WEB = 'web';
    case API = 'api';
    case WORKER = 'worker';
}
