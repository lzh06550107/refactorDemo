<?php

declare(strict_types=1);

return [
    'url_route_must' => true,
    'route_complete_match' => true,
    // Internal identifiers are opaque and may contain UUID-style hyphens.
    'default_route_pattern' => '[\w\.\-]+',
];
