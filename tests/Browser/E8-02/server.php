<?php

// Test-only PHP built-in server router. Launch with explicit sys_temp_dir and
// upload_tmp_dir under this worktree; never use artisan serve's inherited defaults.
if (getenv('APP_ENV') !== 'testing' || getenv('E802_FIXTURE_ENABLED') !== '1') {
    http_response_code(404);

    return;
}
// Only the isolated browser fixture may emulate the OSPanel local environment.
if (getenv('E802_LOCAL_HTTP_TEST') === '1') {
    $root = dirname(__DIR__, 3);
    require_once $root.'/vendor/autoload.php';
    Dotenv\Dotenv::createImmutable($root, '.env.testing')->safeLoad();
    if (($_ENV['DB_DATABASE'] ?? null) !== 'souzpir_test_sp_e8_02'
        || ($_ENV['DB_HOST'] ?? null) !== '127.0.0.1' || ($_ENV['DB_PORT'] ?? null) !== '55490'
        || ($_ENV['DB_USERNAME'] ?? null) !== 'sp_e8_02_app') {
        throw new LogicException('Local HTTP fixture requires the dedicated E8-02 test database.');
    }
    putenv('APP_ENV=local');
    $_ENV['APP_ENV'] = 'local';
    $_SERVER['APP_ENV'] = 'local';
}
$public = realpath(dirname(__DIR__, 3).'/public');
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($public === false || ! is_string($uri)) {
    http_response_code(400);

    return;
}
$requested = realpath($public.'/'.urldecode($uri));
if ($requested && is_file($requested) && str_starts_with($requested, $public.DIRECTORY_SEPARATOR)) {
    $type = match (strtolower(pathinfo($requested, PATHINFO_EXTENSION))) {
        'js' => 'text/javascript',
        'css' => 'text/css',
        'woff2' => 'font/woff2',
        'svg' => 'image/svg+xml',
        default => 'application/octet-stream',
    };
    header('Content-Type: '.$type);
    readfile($requested);

    return;
}
require $public.'/index.php';
