<?php

declare(strict_types=1);

use think\facade\Route;

Route::get('/', 'SpaController/index');
Route::get('<path>', 'SpaController/index')->pattern(['path' => '.*']);
