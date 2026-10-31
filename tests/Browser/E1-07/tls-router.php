<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 3);
$tokenFile = $root.'/.local/e107-proxy-token';
$token = is_file($tokenFile) ? trim((string) file_get_contents($tokenFile)) : '';
$received = $_SERVER['HTTP_X_E107_PROXY_TOKEN'] ?? '';
if (getenv('APP_ENV') !== 'testing' || getenv('E107_HTTPS_HARNESS') !== '1' || realpath($root) !== realpath('C:/OSPanel/home/souzpir.local/.worktrees/sp-e1-07') || ($_SERVER['REMOTE_ADDR'] ?? null) !== '127.0.0.1' || strlen($token) < 32 || ! is_string($received) || ! hash_equals($token, $received)) {
    http_response_code(404);

    return;
}
unset($_SERVER['HTTP_X_E107_PROXY_TOKEN']);
$public = realpath($root.'/public');
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$requested = is_string($uri) ? realpath($public.'/'.urldecode($uri)) : false;
if ($requested && str_starts_with($requested, $public.DIRECTORY_SEPARATOR) && is_file($requested)) {
    $extension = strtolower(pathinfo($requested, PATHINFO_EXTENSION));
    $mime = ['js' => 'text/javascript', 'css' => 'text/css', 'woff2' => 'font/woff2', 'svg' => 'image/svg+xml', 'png' => 'image/png'][$extension] ?? null;
    if ($mime) {
        header('Content-Type: '.$mime);
        readfile($requested);

        return;
    }
}
define('LARAVEL_START', microtime(true));
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->booted(function () use ($app, $root): void {
    if (! $app->environment('testing') || realpath($app->basePath()) !== realpath($root) || config('database.default') !== 'pgsql' || config('database.connections.pgsql.host') !== '127.0.0.1' || (string) config('database.connections.pgsql.port') !== '55607' || config('database.connections.pgsql.database') !== 'souzpir_test_sp_e1_07' || config('runtime.test_database') !== 'souzpir_test_sp_e1_07' || config('runtime.preview_database') === 'souzpir_test_sp_e1_07' || config('features.E1-12.defaults.mail_enabled') !== false || config('features.E1-12.defaults.smtp_enabled') !== false || config('features.E1-12.defaults.recipient') !== null || config('features.E0-04.defaults.external_delivery') !== false || config('mail.default') !== 'log') {
        throw new LogicException('Unsafe E107 test environment.');
    }
    $row = DB::selectOne('select current_database() as db, current_user as role, rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls from pg_roles where rolname = current_user');
    if ($row->db !== 'souzpir_test_sp_e1_07' || $row->role !== 'souzpir_sp_e1_07' || $row->rolsuper || $row->rolcreatedb || $row->rolcreaterole || $row->rolreplication || $row->rolbypassrls) {
        throw new LogicException('Unsafe database identity.');
    }
    config(['trustedproxy.proxies' => ['127.0.0.1']]);
});
$app->handleRequest(Request::capture());
