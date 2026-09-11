<?php

declare(strict_types=1);

use think\facade\Route;

Route::get('/', 'HomeController/index');
Route::get('health', 'HealthController/index');
