<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

// Test-only PHP built-in server router. Launch with explicit sys_temp_dir and
// upload_tmp_dir under this worktree; never use artisan serve's inherited defaults.
if (getenv('APP_ENV') !== 'testing' || getenv('E802_FIXTURE_ENABLED') !== '1') {
    http_response_code(404);

    return;
}
// PHP's built-in server omits SERVER_ADDR. This test-only value is valid only
// for the CI fixture launched explicitly with `php -S 127.0.0.1:<port>`.
if (PHP_SAPI === 'cli-server' && getenv('CI') === 'true' && getenv('E802_CI_BIND_LOOPBACK') === '1'
    && ! array_key_exists('SERVER_ADDR', $_SERVER) && ($_SERVER['REMOTE_ADDR'] ?? null) === '127.0.0.1') {
    $_SERVER['SERVER_ADDR'] = '127.0.0.1';
}
$testApp = null;
// Only the isolated browser fixture may emulate the OSPanel local environment.
if (getenv('E802_LOCAL_HTTP_TEST') === '1') {
    $root = dirname(__DIR__, 3);
    require_once $root.'/vendor/autoload.php';
    Dotenv\Dotenv::createImmutable($root, '.env.testing')->safeLoad();
    $read = static fn (string $name): string => (string) (getenv($name) ?: ($_ENV[$name] ?? ''));
    $ciLocal = $read('CI') === 'true' && $read('E802_CI_FIXTURE') === 'local';
    $database = $ciLocal ? 'souzpir_test_sp_e8_02_local' : 'souzpir_test_sp_e8_02';
    $role = $ciLocal ? 'sp_e8_02_local_app' : 'sp_e8_02_app';
    $port = $ciLocal ? '5432' : '55490';
    $organization = getenv('CABINET_ORGANIZATION_ID');
    $appUrl = getenv('APP_URL');
    $expectedUrl = $ciLocal ? 'http://souzpir.local:8249' : 'http://souzpir.local:8248';
    if (! is_string($organization) || ! preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $organization)
        || $appUrl !== $expectedUrl) {
        throw new LogicException('Local HTTP fixture requires its explicit organization and local URL.');
    }
    // DB_URL can override every individual DB_* field in Laravel. Reject it
    // from any environment source, including a conflicting dotenv value.
    foreach ([getenv('DB_URL'), $_ENV['DB_URL'] ?? null, $_SERVER['DB_URL'] ?? null] as $url) {
        if (is_string($url) && trim($url) !== '') {
            throw new LogicException('Local HTTP fixture refuses a database URL override.');
        }
    }
    if ($read('DB_CONNECTION') !== 'pgsql' || $read('DB_DATABASE') !== $database
        || $read('DB_TEST_DATABASE') !== $database || $read('APP_PREVIEW_DATABASE') === $database
        || $read('DB_HOST') !== '127.0.0.1' || $read('DB_PORT') !== $port || $read('DB_USERNAME') !== $role
        || $read('AI_DRIVER') !== 'fake' || $read('DELIVERY_DRIVER') !== 'fake'
        || $read('SESSION_DRIVER') !== 'file' || $read('CACHE_STORE') !== 'file' || $read('QUEUE_CONNECTION') !== 'sync'
        || ! in_array($read('MAIL_MAILER'), ['log', 'array'], true)) {
        throw new LogicException('Local HTTP fixture requires the dedicated E8-02 test database.');
    }
    // Do not rely on supplied environment values: check the actual server and
    // authenticated role before this router is allowed to enter local mode.
    try {
        $connection = new PDO('pgsql:host=127.0.0.1;port='.$port.';dbname='.$database, $role, $read('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $state = $connection->query('select current_database() as db, current_user as role, host(inet_server_addr()) as host, inet_server_port() as port, case when rolsuper or rolcreatedb or rolcreaterole or rolreplication or rolbypassrls then 1 else 0 end as elevated from pg_roles where rolname = current_user')->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        throw new LogicException('Local HTTP fixture could not verify the dedicated database connection.');
    }
    if (! $state || $state['db'] !== $database || $state['role'] !== $role || $state['host'] !== '127.0.0.1'
        || (int) $state['port'] !== (int) $port || (int) $state['elevated'] !== 0) {
        throw new LogicException('Local HTTP fixture refused an unexpected database connection.');
    }
    // Synchronize the verified process values into every source Laravel's
    // immutable dotenv repository can read; no preview/local .env credentials
    // may supersede the connection that was just checked.
    $verified = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1',
        'DB_PORT' => $port, 'DB_DATABASE' => $database, 'DB_USERNAME' => $role,
        'DB_PASSWORD' => $read('DB_PASSWORD'), 'DB_TEST_DATABASE' => $database, 'DB_URL' => '',
        'APP_PREVIEW_DATABASE' => $read('APP_PREVIEW_DATABASE'), 'AI_DRIVER' => 'fake',
        'DELIVERY_DRIVER' => 'fake', 'MAIL_MAILER' => $read('MAIL_MAILER'),
        'SESSION_DRIVER' => 'file', 'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'sync',
        'CABINET_ORGANIZATION_ID' => $organization, 'APP_URL' => $appUrl];
    foreach (['APP_KEY', 'APP_INSTANCE', 'APP_STORAGE_PATH', 'APP_CONFIG_CACHE', 'APP_ROUTES_CACHE',
        'APP_EVENTS_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'VIEW_COMPILED_PATH',
        'SESSION_COOKIE', 'QUEUE_NAMESPACE'] as $key) {
        $value = getenv($key);
        if (is_string($value) && $value !== '') {
            $verified[$key] = $value;
        }
    }
    foreach ($verified as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    define('LARAVEL_START', microtime(true));
    $testApp = require $root.'/bootstrap/app.php';
    if ($testApp->configurationIsCached()) {
        throw new LogicException('Local HTTP fixture refuses cached application configuration.');
    }
    $testApp->make(Kernel::class)->bootstrap();
    $config = $testApp['config'];
    if ($testApp->environment() !== 'testing' || realpath($testApp->basePath()) !== realpath($root)
        || $config->get('database.default') !== 'pgsql' || $config->get('database.connections.pgsql.url')
        || $config->get('database.connections.pgsql.host') !== '127.0.0.1'
        || (string) $config->get('database.connections.pgsql.port') !== $port
        || $config->get('database.connections.pgsql.database') !== $database
        || $config->get('database.connections.pgsql.username') !== $role
        || $config->get('runtime.test_database') !== $database || $config->get('runtime.preview_database') === $database
        || $config->get('runtime.ai_driver') !== 'fake' || $config->get('runtime.delivery_driver') !== 'fake'
        || $config->get('session.driver') !== 'file' || $config->get('cache.default') !== 'file' || $config->get('queue.default') !== 'sync'
        || $config->get('features.E0-02.defaults.organization_id') !== $organization || $config->get('app.url') !== $appUrl
        || ! in_array($config->get('mail.default'), ['log', 'array'], true)) {
        throw new LogicException('Local HTTP fixture refused unexpected bootstrapped configuration.');
    }
    $actual = $testApp['db']->connection()->selectOne('select current_database() as db, current_user as role, host(inet_server_addr()) as host, inet_server_port() as port, rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls from pg_roles where rolname = current_user');
    if (! $actual || $actual->db !== $database || $actual->role !== $role || $actual->host !== '127.0.0.1'
        || (int) $actual->port !== (int) $port || $actual->rolsuper || $actual->rolcreatedb || $actual->rolcreaterole
        || $actual->rolreplication || $actual->rolbypassrls) {
        throw new LogicException('Local HTTP fixture refused the bootstrapped database connection.');
    }
    $testApp->instance('env', 'local');
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
if ($testApp !== null) {
    $testApp->handleRequest(Request::capture());

    return;
}
require $public.'/index.php';
