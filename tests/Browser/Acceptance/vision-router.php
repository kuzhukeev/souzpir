<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

$token = getenv('E901B_PROXY_TOKEN');
if (PHP_SAPI !== 'cli-server' || getenv('RUN_ACCEPTANCE_E901B') !== '1' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
    || ! is_string($token) || strlen($token) !== 64 || ! hash_equals($token, (string) ($_SERVER['HTTP_X_ACCEPTANCE_TLS'] ?? ''))) {
    http_response_code(403);
    exit;
}
unset($_SERVER['HTTP_X_ACCEPTANCE_TLS']);
$_SERVER['SERVER_ADDR'] = '127.0.0.1';
$_SERVER['HTTPS'] = 'on';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/__acceptance/widget') {
    $key = $_GET['key'] ?? '';
    if (! is_string($key) || ! preg_match('/^[A-Za-z0-9]{64}$/D', $key)) {
        http_response_code(400);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ru"><head><title>Тестовая страница</title></head><body><h1>Тестовая страница</h1><script src="/widget.js?key='.$key.'&amp;v=1" defer></script></body></html>';
    exit;
}
$public = realpath(__DIR__.'/../../../public');
$file = is_string($path) ? realpath($public.$path) : false;
if ($file && str_starts_with($file, $public.DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}
define('LARAVEL_START', microtime(true));
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['features.E0-05.defaults.fixture_directory' => base_path('.local/runtime/e901b-fixtures'), 'features.E8-01.defaults.parser' => ['model' => 'google/gemini-2.5-flash', 'temperature' => 0.0, 'max_tokens' => 1200]]);
$app->handleRequest(Request::capture());
