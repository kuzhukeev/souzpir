<?php

// PHP's built-in server omits SERVER_ADDR; only these disposable CI listeners
// bind loopback. Keep the production request path and TLS checks unchanged.
if (PHP_SAPI !== 'cli-server' || getenv('CI') !== 'true' || getenv('APP_ENV') !== 'testing'
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
    || ! in_array($_SERVER['SERVER_PORT'] ?? '', ['8101', '8238', '8241'], true)) {
    http_response_code(404);

    return;
}
$_SERVER['SERVER_ADDR'] = '127.0.0.1';
$public = realpath(dirname(__DIR__, 2).'/public');
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requested = realpath($public.'/'.urldecode($uri));
if ($requested && is_file($requested) && str_starts_with($requested, $public.DIRECTORY_SEPARATOR)) {
    return false;
}
require $public.'/index.php';
