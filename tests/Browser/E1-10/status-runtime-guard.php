<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

if (getenv('APP_ENV') !== 'testing' || getenv('E110_BROWSER_FIXTURE') !== '1') {
    throw new LogicException('Isolated browser fixture required.');
}
require_once getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$root = realpath(getcwd());
$db = DB::connection('pgsql');
$expectedDb = getenv('E110_TEST_DB');
$expectedPort = getenv('E110_TEST_PORT');
$expectedRole = getenv('E110_TEST_ROLE');
if (! $root || ! is_file($root.'/.git') || realpath($app->basePath()) !== $root || realpath(Application::inferBasePath()) !== $root
    || ! $app->environment('testing') || ! is_string($expectedDb) || ! preg_match('/^souzpir_test_[a-z0-9_]+$/D', $expectedDb)
    || ! is_string($expectedPort) || ! ctype_digit($expectedPort) || ! is_string($expectedRole) || $expectedRole === ''
    || $db->getDatabaseName() !== $expectedDb || config('runtime.test_database') !== $expectedDb
    || ! is_string(config('runtime.preview_database')) || config('runtime.preview_database') === ''
    || config('runtime.preview_database') === $expectedDb || $db->getConfig('username') !== $expectedRole
    || config('session.driver') !== 'database' || config('cache.default') !== 'database') {
    throw new LogicException('Dedicated worktree and shared-session test database required.');
}
$actual = $db->selectOne(<<<'SQL'
    SELECT current_database() AS database, current_user AS role, session_user = current_user AS same_role,
           host(inet_server_addr()) AS host, host(inet_client_addr()) AS client, inet_server_port() AS port,
           r.rolsuper, r.rolcreatedb, r.rolcreaterole, r.rolreplication, r.rolbypassrls, d.datdba = r.oid AS owner,
           EXISTS (SELECT 1 FROM pg_roles elevated WHERE elevated.oid <> r.oid
             AND (elevated.rolsuper OR elevated.rolcreatedb OR elevated.rolcreaterole OR elevated.rolreplication OR elevated.rolbypassrls)
             AND pg_has_role(r.oid, elevated.oid, 'MEMBER')) AS privileged_member
    FROM pg_roles r JOIN pg_database d ON d.datname = current_database() WHERE r.rolname = current_user
    SQL);
if (! $actual || $actual->database !== $expectedDb || $actual->role !== $expectedRole || $actual->host !== '127.0.0.1'
    || $actual->client !== '127.0.0.1' || (string) $actual->port !== $expectedPort || ! $actual->same_role || ! $actual->owner
    || $actual->rolsuper || $actual->rolcreatedb || $actual->rolcreaterole || $actual->rolreplication || $actual->rolbypassrls || $actual->privileged_member) {
    throw new LogicException('Unsafe browser test database identity.');
}

return $app;
