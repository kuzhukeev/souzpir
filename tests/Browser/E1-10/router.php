<?php

use Illuminate\Http\Request;

if (getenv('APP_ENV') !== 'testing' || getenv('E110_BROWSER_FIXTURE') !== '1' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(404);
    exit;
}
// The PHP development server omits SERVER_ADDR; it is bound only to loopback.
$_SERVER['SERVER_ADDR'] = '127.0.0.1';
$app = require __DIR__.'/runtime-guard.php';
$app->handleRequest(Request::capture());
