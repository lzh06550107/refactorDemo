<?php

declare(strict_types=1);

use app\worker\command\AdminBootstrapCommand;
use app\worker\command\OpenPlatformProvisioningWorkerCommand;

return [
    'commands' => [
        AdminBootstrapCommand::class,
        OpenPlatformProvisioningWorkerCommand::class,
    ],
];
