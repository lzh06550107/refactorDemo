<?php

declare(strict_types=1);

use think\facade\Route;

Route::get('v1/health', 'V1.HealthController/index');
