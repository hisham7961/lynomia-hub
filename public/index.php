<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// وضع الصيانة أثناء التحديثات
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/../vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
