<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// This preflight runs in the same environment as the following migration or
// worker. Never infer safety merely from a supplied database name.
if (PHP_SAPI !== 'cli' || getenv('CI') !== 'true' || getenv('APP_ENV') !== 'testing') {
    throw new LogicException('Browser preflight requires an explicitly isolated CI process.');
}
$pairs = [
    'souzpir_test_sp_invitation_ci' => 'sp_invitation_ci_app',
    'souzpir_test_sp_e1_05' => 'sp_e1_05_app',
    'souzpir_test_sp_e8_02' => 'sp_e8_02_app',
    'souzpir_test_sp_e8_02_local' => 'sp_e8_02_local_app',
    'souzpir_test_sp_e8_03' => 'sp_e8_03_app',
];
$database = getenv('DB_DATABASE');
$role = is_string($database) ? ($pairs[$database] ?? null) : null;
if (! $role || getenv('DB_CONNECTION') !== 'pgsql' || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_PORT') !== '5432' || getenv('DB_USERNAME') !== $role
    || getenv('DB_TEST_DATABASE') !== $database || getenv('APP_PREVIEW_DATABASE') === $database
    || (getenv('DB_URL') !== false && getenv('DB_URL') !== '')) {
    throw new LogicException('Refusing an unexpected browser database configuration.');
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$root = realpath(__DIR__.'/../..');
$state = DB::selectOne('select current_database() as db, current_user as role, inet_server_addr() as host, inet_server_port() as port, rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls from pg_roles where rolname = current_user');
if (! $root || realpath(base_path()) !== $root || realpath(getcwd()) !== $root || ! $app->environment('testing')
    || config('database.default') !== 'pgsql' || config('database.connections.pgsql.url')
    || DB::connection()->getDatabaseName() !== $database || config('runtime.test_database') !== $database
    || config('runtime.preview_database') === $database || ! $state
    || $state->db !== $database || $state->role !== $role || $state->host !== '127.0.0.1' || (int) $state->port !== 5432
    || $state->rolsuper || $state->rolcreatedb || $state->rolcreaterole || $state->rolreplication || $state->rolbypassrls) {
    throw new LogicException('Refusing an unexpected actual browser database connection.');
}
echo "Verified isolated browser database: $database\n";
