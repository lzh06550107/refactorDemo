<?php

declare(strict_types=1);

return [
    'deployment_profile' => env('WEPLATFORM_DEPLOYMENT_PROFILE', 'selfhost-multi-tenant'),
    'legacy_root' => env('WEPLATFORM_LEGACY_ROOT', ''),
    'legacy_base_url' => env('WEPLATFORM_LEGACY_BASE_URL', ''),
    'trusted_hosts' => array_values(array_filter(explode(',', (string) env('WEPLATFORM_TRUSTED_HOSTS', '')))),
    'trusted_proxies' => array_values(array_filter(explode(',', (string) env('WEPLATFORM_TRUSTED_PROXIES', '')))),
    'audit_channel' => env('WEPLATFORM_AUDIT_CHANNEL', 'file'),
    'admin_session_pepper' => env('WEPLATFORM_ADMIN_SESSION_PEPPER', ''),
    'admin_cookie_secure' => (bool) env('WEPLATFORM_ADMIN_COOKIE_SECURE', true),
    'admin_session_ttl_seconds' => (int) env('WEPLATFORM_ADMIN_SESSION_TTL_SECONDS', 28800),
    'web_assets_dev' => (bool) env('WEPLATFORM_WEB_ASSETS_DEV', false),
    'web_assets_dev_origin' => (string) env('WEPLATFORM_WEB_ASSETS_DEV_ORIGIN', 'http://127.0.0.1:5174'),
];
