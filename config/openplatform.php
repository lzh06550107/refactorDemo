<?php

declare(strict_types=1);

return [
    'authorization_callback_uri' => env('WEPLATFORM_OPENPLATFORM_AUTHORIZATION_CALLBACK_URI', ''),
    'http_timeout_seconds' => (int) env('WEPLATFORM_OPENPLATFORM_HTTP_TIMEOUT_SECONDS', 10),
    'secret_key_version' => env('WEPLATFORM_OPENPLATFORM_SECRET_KEY_VERSION', 'v1'),
    'secret_key_base64' => env('WEPLATFORM_OPENPLATFORM_SECRET_KEY_BASE64', ''),
];
