<?php

declare(strict_types=1);

namespace modules\openplatform\domain;

enum ProvisioningJobStatus: string
{
    case READY = 'ready';
    case CLAIMED = 'claimed';
    case COMPLETED = 'completed';
    case DEAD = 'dead';
}
