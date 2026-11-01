<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// The PHP test server does not populate SERVER_ADDR. Never use this router outside
// the isolated testing environment or with a non-loopback client.
if (getenv('APP_ENV') !== 'testing' || getenv('E106_BROWSER_FIXTURE') !== '1' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(404);
    exit;
}
$_SERVER['SERVER_ADDR'] = '127.0.0.1';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$public = realpath(getcwd().'/public');
$file = is_string($path) ? realpath(getcwd().'/public'.$path) : false;
if ($public && $file && str_starts_with(strtolower($file), strtolower($public.DIRECTORY_SEPARATOR)) && is_file($file)) {
    return false;
}
if (getenv('E106_TEST_PERMISSIONS') === 'split') {
    define('LARAVEL_START', microtime(true));
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->booted(static function () use ($app): void {
        $db = DB::connection('pgsql');
        $root = realpath(getcwd());
        $local = realpath($root.'/.local');
        $data = realpath($root.'/.local/postgres/data');
        if (! $app->environment('testing') || realpath($app->basePath()) !== $root || ! $local || ! $data
            || is_link($root.'/.local/postgres/data') || ! str_starts_with(strtolower($data), strtolower($local.DIRECTORY_SEPARATOR))
            || $db->getConfig('host') !== '127.0.0.1' || (string) $db->getConfig('port') !== '55861'
            || $db->getDatabaseName() !== 'souzpir_test_sp_e1_06' || $db->getConfig('username') !== 'sp_e106_app'
            || config('runtime.test_database') !== 'souzpir_test_sp_e1_06' || config('runtime.preview_database') === 'souzpir_test_sp_e1_06') {
            throw new LogicException('Refusing split-permission test outside isolated database.');
        }
        $admin = new PDO('pgsql:host=127.0.0.1;port=55861;dbname=postgres', 'sp_e106_admin', '', [PDO::ATTR_TIMEOUT => 5]);
        if (realpath($admin->query('SHOW data_directory')->fetchColumn()) !== $data) {
            throw new LogicException('Unexpected split-permission database cluster.');
        }
        $identity = $db->selectOne('select current_database() as db, current_user as role, rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls from pg_roles where rolname=current_user');
        if ($identity->db !== 'souzpir_test_sp_e1_06' || $identity->role !== 'sp_e106_app'
            || $identity->rolsuper || $identity->rolcreatedb || $identity->rolcreaterole || $identity->rolreplication || $identity->rolbypassrls) {
            throw new LogicException('Unexpected split-permission database identity.');
        }
        $abilities = config('permissions.abilities');
        $abilities['team.write'] = ['admin', 'viewer'];
        $abilities['team.manage'] = ['owner'];
        config(['permissions.abilities' => $abilities]);
    });
    $app->handleRequest(Request::capture());

    return;
}
require getcwd().'/public/index.php';
