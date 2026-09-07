<?php

declare(strict_types=1);

return [
    'connection' => env('LEGACY_DB_CONNECTION', 'mysql'),
    'prefix' => env('LEGACY_DB_PREFIX', 'ims_'),
];
