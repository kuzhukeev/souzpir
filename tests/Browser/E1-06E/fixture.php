<?php

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('pgsql');
$root = realpath(getcwd());
$target = getenv('E106E_TEST_DB');
$port = getenv('E106E_TEST_PORT');
$role = getenv('E106E_TEST_ROLE');
if (getenv('E106E_BROWSER_FIXTURE') !== '1' || ! app()->environment('testing') || ! $root
    || realpath(app()->basePath()) !== $root || realpath(Application::inferBasePath()) !== $root
    || ! is_string($target) || ! preg_match('/^souzpir_test_[a-z0-9_]+$/D', $target)
    || ! is_string($port) || ! ctype_digit($port) || ! is_string($role) || $role === ''
    || $db->getDatabaseName() !== $target || config('runtime.test_database') !== $target
    || ! is_string(config('runtime.preview_database')) || config('runtime.preview_database') === ''
    || config('runtime.preview_database') === $target || $db->getConfig('username') !== $role) {
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
Artisan::call('migrate', ['--force' => true]);
$orgId = '01J00000000000000000000000';
if (Organization::whereKey($orgId)->exists() || User::where('email', 'owner-e106e@example.test')->exists()) {
    throw new LogicException('Fixture already exists.');
}
DB::transaction(function () use ($orgId): void {
    $owner = User::create(['name' => 'Тестовый владелец', 'email' => 'owner-e106e@example.test', 'password' => 'test-only-password-123']);
    $viewer = User::create(['name' => 'Тестовый наблюдатель', 'email' => 'viewer-e106e@example.test', 'password' => 'test-only-password-123']);
    $owner->forceFill(['email_verified_at' => now()])->save();
    $viewer->forceFill(['email_verified_at' => now()])->save();
    $org = new Organization;
    $org->forceFill(['id' => $orgId, 'name' => 'Тестовая организация', 'owner_user_id' => $owner->id])->save();
    app(TenantContextRunner::class)->run(new TenantContext($orgId, actorId: $owner->id, source: 'job'), function () use ($owner, $viewer): void {
        $first = Brand::create(['name' => 'Северные огни — длинное название бренда', 'theme' => 'pyro']);
        Brand::create(['name' => 'Карамельная мастерская', 'theme' => 'confectionery']);
        Membership::create(['user_id' => $owner->id, 'role' => 'owner']);
        Membership::create(['user_id' => $viewer->id, 'role' => 'viewer', 'brand_ids' => [$first->id]]);
    });
});
echo "Synthetic fixture ready.\n";
