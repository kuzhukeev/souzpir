<?php

if (getenv('APP_ENV') !== 'testing' || getenv('E106E_BROWSER_FIXTURE') !== '1' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(404);
    exit;
}
// PHP's built-in test server omits SERVER_ADDR; this fixture is bound only to 127.0.0.1:8231.
$_SERVER['SERVER_ADDR'] = '127.0.0.1';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/_e106e') {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo <<<'HTML'
<!doctype html><html lang="ru" data-theme="neutral"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Тест приглашения</title>
<script type="module">import RefreshRuntime from 'http://127.0.0.1:5178/@react-refresh'; RefreshRuntime.injectIntoGlobalHook(window); window.$RefreshReg$=()=>{}; window.$RefreshSig$=()=>type=>type; window.__vite_plugin_react_preamble_installed__=true;</script>
<script type="module" src="http://127.0.0.1:5178/@vite/client"></script>
<script type="module" src="http://127.0.0.1:5178/tests/Browser/E1-06E/harness.tsx"></script></head><body><div id="root"></div></body></html>
HTML;
    exit;
}
if ($path === '/_e106e/count') {
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = \Illuminate\Support\Facades\DB::connection('pgsql');
    $expected = getenv('E106E_TEST_DB');
    $role = getenv('E106E_TEST_ROLE');
    $port = getenv('E106E_TEST_PORT');
    $email = $_GET['email'] ?? null;
    $actual = $db->selectOne('select current_database() as db, current_user as role, host(inet_server_addr()) as host, inet_server_port() as port');
    if (! app()->environment('testing') || ! is_string($expected) || ! preg_match('/^souzpir_test_[a-z0-9_]+$/D', $expected)
        || $db->getDatabaseName() !== $expected || config('runtime.test_database') !== $expected || config('runtime.preview_database') === $expected
        || $actual->db !== $expected || $actual->role !== $role || $actual->host !== '127.0.0.1' || (string) $actual->port !== $port
        || ! is_string($email) || ! str_ends_with($email, '@example.test') || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        http_response_code(403);
        exit;
    }
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['count' => $db->table('invitations')->where('email', $email)->count()], JSON_THROW_ON_ERROR);
    exit;
}
$public = realpath(getcwd().'/public');
$file = is_string($path) ? realpath(getcwd().'/public'.$path) : false;
if ($public && $file && str_starts_with(strtolower($file), strtolower($public.DIRECTORY_SEPARATOR)) && is_file($file)) {
    return false;
}
require getcwd().'/public/index.php';
