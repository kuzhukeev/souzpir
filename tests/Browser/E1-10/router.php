<?php

use Illuminate\Http\Request;

if (getenv('APP_ENV') !== 'testing' || getenv('E110_BROWSER_FIXTURE') !== '1' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(404);
    exit;
}
$public = realpath(getcwd().'/public');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$file = is_string($public) && is_string($path) ? realpath($public.$path) : false;
if ($file !== false && str_starts_with($file, $public.DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}
// The PHP development server omits SERVER_ADDR; it is bound only to loopback.
$_SERVER['SERVER_ADDR'] = '127.0.0.1';
$app = require __DIR__.'/runtime-guard.php';
$app->handleRequest(Request::capture());
