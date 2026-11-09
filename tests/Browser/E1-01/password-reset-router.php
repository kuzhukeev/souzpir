<?php

// PHP's development server does not provide SERVER_ADDR; this router is only
// for the isolated loopback browser fixture, never an application entry point.
if (getenv('E101G_BROWSER_ROUTER') !== '1' || getenv('APP_ENV') !== 'testing'
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403);
    exit;
}
$_SERVER['SERVER_ADDR'] = '127.0.0.1';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = realpath(__DIR__.'/../../../public'.(is_string($path) ? $path : '/'));
$public = realpath(__DIR__.'/../../../public');
if ($file && $public && str_starts_with(strtolower($file), strtolower($public).DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}
require __DIR__.'/../../../public/index.php';
