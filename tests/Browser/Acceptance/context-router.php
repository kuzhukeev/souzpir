<?php

if (PHP_SAPI !== 'cli-server' || getenv('RUN_ACCEPTANCE_E901C') !== '1' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403);
    exit;
}
$_SERVER['SERVER_ADDR'] = '127.0.0.1';
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/__acceptance/widget') {
    $key = $_GET['key'] ?? '';
    if (! is_string($key) || ! preg_match('/^[A-Za-z0-9]{64}$/D', $key)) {
        http_response_code(400);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ru"><head><title>Тестовая страница</title></head><body><h1>Тестовая страница</h1><script src="/widget.js?key='.$key.'&amp;v=1" defer></script></body></html>';
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$public = realpath(__DIR__.'/../../../public');
$file = is_string($path) ? realpath($public.$path) : false;
if ($file && str_starts_with($file, $public.DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}
require $public.'/index.php';
