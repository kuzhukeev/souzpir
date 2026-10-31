<?php

// Test-only PHP built-in server router. Launch with explicit sys_temp_dir and
// upload_tmp_dir under this worktree; never use artisan serve's inherited defaults.
if (getenv('APP_ENV') !== 'testing' || getenv('E802_FIXTURE_ENABLED') !== '1') {
    http_response_code(404);

    return;
}
$public = realpath(dirname(__DIR__, 3).'/public');
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($public === false || ! is_string($uri)) {
    http_response_code(400);

    return;
}
$requested = realpath($public.'/'.urldecode($uri));
if ($requested && is_file($requested) && str_starts_with($requested, $public.DIRECTORY_SEPARATOR)) {
    return false;
}
require $public.'/index.php';
