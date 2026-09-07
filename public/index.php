<?php

declare(strict_types=1);

use think\App;

require __DIR__ . '/../vendor/autoload.php';

$http = (new App())->http;
$response = $http->run();
$response->send();
$http->end($response);
