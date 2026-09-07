<?php

declare(strict_types=1);

namespace app\common\contract;

use app\common\audit\AuditEvent;

interface AuditLogger
{
    public function record(AuditEvent $event): void;
}
