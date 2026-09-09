<?php

declare(strict_types=1);

use app\api\middleware\OpenPlatformAdminContextMiddleware;
use think\facade\Route;

Route::get('v1/health', 'V1.HealthController/index');
Route::post('v1/openplatform/components/:componentPlatformId/events', 'V1.OpenPlatformEventController/receive');
Route::post('v1/openplatform/components/:componentPlatformId/ticket', 'V1.OpenPlatformTicketController/receive');
Route::post('v1/openplatform/components/:componentPlatformId/authorization-intents', 'V1.OpenPlatformAuthorizationStartController/create')
    ->middleware(OpenPlatformAdminContextMiddleware::class);
Route::get('v1/openplatform/authorization/callback', 'V1.OpenPlatformAuthorizationCallbackController/receive');
Route::get('v1/openplatform/provisionings/:id', 'V1.OpenPlatformProvisioningController/show')
    ->middleware(OpenPlatformAdminContextMiddleware::class);
Route::post('v1/openplatform/provisionings/:id/retry', 'V1.OpenPlatformProvisioningController/retry')
    ->middleware(OpenPlatformAdminContextMiddleware::class);
Route::post('v1/openplatform/components/:componentPlatformId/authorizers/:authorizerAppId/metadata/refresh', 'V1.OpenPlatformAuthorizerMetadataController/refresh')
    ->middleware(OpenPlatformAdminContextMiddleware::class);
