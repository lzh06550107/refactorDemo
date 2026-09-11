<?php

declare(strict_types=1);

use app\admin\middleware\AdminCsrfMiddleware;
use app\admin\middleware\AdminSessionCookieMiddleware;
use think\facade\Route;

Route::get('health', 'HealthController/index');

Route::group('v1/auth', function (): void {
    Route::get('csrf', 'V1.AdminAuthController/csrf');
    Route::post('login', 'V1.AdminAuthController/login')
        ->middleware(AdminCsrfMiddleware::class);
    Route::get('me', 'V1.AdminAuthController/me')
        ->middleware(AdminSessionCookieMiddleware::class);
    Route::post('logout', 'V1.AdminAuthController/logout')
        ->middleware(AdminSessionCookieMiddleware::class)
        ->middleware(AdminCsrfMiddleware::class);
});
