<?php

use Illuminate\Http\Request;

if (getenv('APP_ENV') !== 'testing' || getenv('E110_BROWSER_FIXTURE') !== '1' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(404);
    exit;
}
// The PHP development server omits SERVER_ADDR; it is bound only to loopback.
$_SERVER['SERVER_ADDR'] = '127.0.0.1';
$app = require __DIR__.'/status-runtime-guard.php';
if (getenv('E110_STATUS_DISABLED') === '1') {
    config(['cache.limiter' => 'status-unavailable', 'cache.stores.status-unavailable.driver' => 'null']);
}
$app->handleRequest(Request::capture());
