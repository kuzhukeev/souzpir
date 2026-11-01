<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('pgsql');
$root = realpath(getcwd());
$target = getenv('E106E_TEST_DB');
$port = getenv('E106E_TEST_PORT');
$role = getenv('E106E_TEST_ROLE');
$requested = $argv[1] ?? '';
if (getenv('E106E_BROWSER_FIXTURE') !== '1' || ! app()->environment('testing') || ! $root
    || ! is_file($root.'/.git') || realpath(app()->basePath()) !== $root || realpath(Application::inferBasePath()) !== $root
    || ! is_string($target) || ! preg_match('/^souzpir_test_[a-z0-9_]+$/D', $target)
    || ! is_string($port) || ! ctype_digit($port) || ! is_string($role) || $role === ''
    || $db->getDatabaseName() !== $target || config('runtime.test_database') !== $target
    || ! is_string(config('runtime.preview_database')) || config('runtime.preview_database') === ''
    || config('runtime.preview_database') === $target || $db->getConfig('username') !== $role
    || ! in_array($requested, ['owner', 'viewer'], true)) {
    throw new LogicException('Dedicated browser test database required.');
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
if (! $actual || $actual->database !== $target || $actual->role !== $role || $actual->host !== '127.0.0.1'
    || $actual->client !== '127.0.0.1' || (string) $actual->port !== $port || ! $actual->same_role || ! $actual->owner
    || $actual->rolsuper || $actual->rolcreatedb || $actual->rolcreaterole || $actual->rolreplication || $actual->rolbypassrls || $actual->privileged_member) {
    throw new LogicException('Unsafe browser test database identity.');
}
DB::transaction(function () use ($requested): void {
    $org = '01J00000000000000000000000';
    $owner = DB::table('users')->where('email', 'owner-e106e@example.test')->value('id');
    if (! $owner || ! DB::table('organizations')->where('id', $org)->where('owner_user_id', $owner)->exists()) {
        throw new LogicException('Synthetic fixture owner not found.');
    }
    $query = DB::table('memberships')->where('organization_id', $org)->where('user_id', $owner)->where('status', 'active');
    $current = $query->lockForUpdate()->first();
    if (! $current || ! in_array($current->role, ['owner', 'viewer'], true)) throw new LogicException('Unexpected synthetic membership.');
    $brandIds = $requested === 'viewer' ? DB::table('brands')->where('organization_id', $org)->pluck('id')->all() : [];
    if ($requested === 'viewer' && count($brandIds) !== 2) throw new LogicException('Unexpected synthetic brands.');
    $query->update(['role' => $requested, 'brand_ids' => json_encode($brandIds, JSON_THROW_ON_ERROR)]);
});
echo "Synthetic fixture role updated.\n";
