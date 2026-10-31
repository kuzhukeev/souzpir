<?php

$expected = realpath(__DIR__.'/../../../.local/tmp/rollout-synthetic');
if (! is_string($expected) || getcwd() !== $expected || getenv('MFA_LOCAL_FIXTURE') !== '1'
    || getenv('APP_ENV') !== 'local' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
    || ($_SERVER['SERVER_PORT'] ?? '') !== '8292') {
    http_response_code(404);

    return;
}
$_SERVER['SERVER_ADDR'] = '127.0.0.1';
$public = realpath($expected.'/public');
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requested = realpath($public.'/'.urldecode($uri));
if ($requested && is_file($requested) && str_starts_with($requested, $public.DIRECTORY_SEPARATOR)) {
    return false;
}
require $public.'/index.php';
