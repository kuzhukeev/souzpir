<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// Isolated test router. Use -t public and explicit sys_temp_dir/upload_tmp_dir
// under this worktree. This process must start as APP_ENV=testing.
if (getenv('APP_ENV') !== 'testing' || getenv('E803_FIXTURE_ENABLED') !== '1') {
    http_response_code(404);

    return;
}
// PHP's built-in server omits SERVER_ADDR. This test-only value is valid only
// for the CI fixture launched explicitly with `php -S 127.0.0.1:<port>`.
if (PHP_SAPI === 'cli-server' && getenv('CI') === 'true' && getenv('E803_CI_BIND_LOOPBACK') === '1'
    && ! array_key_exists('SERVER_ADDR', $_SERVER) && ($_SERVER['REMOTE_ADDR'] ?? null) === '127.0.0.1') {
    $_SERVER['SERVER_ADDR'] = '127.0.0.1';
}
$root = realpath(dirname(__DIR__, 3));
$public = $root === false ? false : realpath($root.'/public');
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($public === false || ! is_string($uri)) {
    http_response_code(400);

    return;
}
$testApp = null;
if (getenv('E803_LOCAL_HTTP_TEST') === '1') {
    // Bootstrap the testing env and validate the actual database before changing
    // the container environment only. This forces CSRF on for realistic browser
    // requests while leaving the process env and .env.testing unchanged.
    define('LARAVEL_START', microtime(true));
    require_once $root.'/vendor/autoload.php';
    $testApp = require $root.'/bootstrap/app.php';
    $testApp->make(Kernel::class)->bootstrap();
    Http::preventStrayRequests();
    $connection = DB::connection();
    $state = DB::selectOne('select current_database() as db, current_user as role, inet_server_addr() as host, inet_server_port() as port, rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls from pg_roles where rolname = current_user');
    $expectedPort = getenv('CI') === 'true' && getenv('E803_CI_FIXTURE') === '1' ? 5432 : 55500;
    $base = str_replace('\\', '/', base_path());
    $storage = str_replace('\\', '/', storage_path());
    $expected = str_replace('\\', '/', $root);
    if (! $testApp->environment('testing') || $base !== $expected
        || $connection->getDatabaseName() !== 'souzpir_test_sp_e8_03' || config('runtime.test_database') !== 'souzpir_test_sp_e8_03'
        || config('runtime.preview_database') === 'souzpir_test_sp_e8_03' || ! $state
        || $state->db !== 'souzpir_test_sp_e8_03' || $state->role !== 'sp_e8_03_app'
        || $state->host !== '127.0.0.1' || (int) $state->port !== $expectedPort || $state->rolsuper || $state->rolcreatedb || $state->rolcreaterole || $state->rolreplication || $state->rolbypassrls
        || ! str_starts_with($storage.'/', $expected.'/.local/runtime/testing/storage/')
        || config('runtime.ai_driver') !== 'fake' || config('runtime.delivery_driver') !== 'fake'
        || ! in_array(config('mail.default'), ['log', 'array'], true)
        || config('features.E8-01B.defaults.fetch_enabled', false)) {
        throw new LogicException('Refusing browser server outside isolated E8-03 testing environment.');
    }
    $testApp->instance('env', 'local');
}
$requested = realpath($public.'/'.urldecode($uri));
if ($requested && is_file($requested) && str_starts_with($requested, $public.DIRECTORY_SEPARATOR)) {
    $extension = strtolower(pathinfo($requested, PATHINFO_EXTENSION));
    $types = ['js' => 'text/javascript', 'css' => 'text/css', 'woff2' => 'font/woff2', 'svg' => 'image/svg+xml',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'ico' => 'image/x-icon'];
    if (isset($types[$extension])) {
        header('Content-Type: '.$types[$extension]);
        readfile($requested);

        return;
    }
}
if ($testApp !== null) {
    if (is_file($maintenance = $testApp->storagePath('framework/maintenance.php'))) {
        require $maintenance;
    }
    $testApp->handleRequest(Request::capture());

    return;
}
require $public.'/index.php';
