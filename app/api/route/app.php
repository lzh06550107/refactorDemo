<?php

declare(strict_types=1);

use think\facade\Route;

Route::get('v1/health', 'V1.HealthController/index');
Route::post('v1/openplatform/components/:componentPlatformId/ticket', 'V1.OpenPlatformTicketController/receive');
