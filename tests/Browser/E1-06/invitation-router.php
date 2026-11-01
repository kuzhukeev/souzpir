<?php

// The PHP test server does not populate SERVER_ADDR. Never use this router outside
// the isolated testing environment or with a non-loopback client.
if (getenv('APP_ENV') !== 'testing' || getenv('E106_BROWSER_FIXTURE') !== '1' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(404);
    exit;
}
$_SERVER['SERVER_ADDR'] = '127.0.0.1';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$public = realpath(getcwd().'/public');
$file = is_string($path) ? realpath(getcwd().'/public'.$path) : false;
if ($public && $file && str_starts_with(strtolower($file), strtolower($public.DIRECTORY_SEPARATOR)) && is_file($file)) {
    return false;
}
require getcwd().'/public/index.php';
