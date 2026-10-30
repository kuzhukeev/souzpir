<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

// The maintenance file follows this checkout's isolated storage path.
if (file_exists($maintenance = $app->storagePath('framework/maintenance.php'))) {
    require $maintenance;
}

$app->handleRequest(Request::capture());
