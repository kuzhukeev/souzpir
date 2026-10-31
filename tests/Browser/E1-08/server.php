<?php

// Test-only router for the isolated PHP built-in server. Keep upload temp paths
// explicit in the command; artisan serve does not inherit PHP -d directives.
if (getenv('APP_ENV') !== 'testing') {
    http_response_code(404);

    return;
}
$public = realpath(dirname(__DIR__, 3).'/public');
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requested = realpath($public.'/'.urldecode($uri));
if ($requested && is_file($requested) && str_starts_with($requested, $public.DIRECTORY_SEPARATOR)) {
    return false;
}
require $public.'/index.php';
