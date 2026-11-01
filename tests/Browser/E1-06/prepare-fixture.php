<?php

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Team\Models\Invitation;
use App\Tenancy\Cabinet;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection();
$root = realpath(__DIR__.'/../../..');
$local = realpath($root.'/.local');
$data = realpath((string) getenv('SP_ROLE_TEST_DATA'));
$testHost = (string) getenv('SP_ROLE_TEST_HOST');
$port = (string) getenv('SP_ROLE_TEST_PORT');
$database = (string) getenv('SP_ROLE_TEST_DB');
$role = (string) getenv('SP_ROLE_TEST_OWNER');
$adminRole = (string) getenv('SP_ROLE_TEST_ADMIN');
$organizationId = (string) getenv('E106_FIXTURE_ORG');
if (getenv('E106_BROWSER_FIXTURE') !== '1' || ! $app->environment('testing') || realpath(getcwd()) !== $root
    || realpath($app->basePath()) !== $root || realpath(Application::inferBasePath()) !== $root
    || ! $local || ! $data || ! str_starts_with(strtolower($data), strtolower($local.DIRECTORY_SEPARATOR))
    || filter_var($testHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || ! str_starts_with($testHost, '127.')
    || ! ctype_digit($port) || ! preg_match('/^souzpir_test_[a-z0-9_]+$/D', $database)
    || ! preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $role) || ! preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $adminRole)
    || $role === $adminRole || $db->getDriverName() !== 'pgsql' || $db->getConfig('host') !== $testHost
    || (string) $db->getConfig('port') !== $port || $db->getDatabaseName() !== $database || $db->getConfig('username') !== $role
    || config('runtime.test_database') !== $database || config('runtime.preview_database') === $database || ! Str::isUlid($organizationId)
    || app(Cabinet::class)->organizationId() !== $organizationId) {
    throw new LogicException('Refusing a non-isolated Team browser fixture.');
}
$admin = new PDO("pgsql:host={$testHost};port={$port};dbname=postgres", $adminRole, '', [PDO::ATTR_TIMEOUT => 5]);
if (realpath($admin->query('SHOW data_directory')->fetchColumn()) !== $data
    || $admin->query("SELECT host(inet_server_addr()) || '|' || inet_server_port()")->fetchColumn() !== $testHost.'|'.$port) {
    throw new LogicException('Unexpected test cluster data directory.');
}
$admin = null;
$identity = $db->selectOne('select current_database() as db, current_user as role, host(inet_server_addr()) as address, inet_server_port() as port, rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls from pg_roles where rolname=current_user');
if ($identity->db !== $database || $identity->role !== $role || $identity->address !== $testHost || (string) $identity->port !== $port
    || $identity->rolsuper || $identity->rolcreatedb || $identity->rolcreaterole || $identity->rolreplication || $identity->rolbypassrls) {
    throw new LogicException('Unexpected test database identity or role privileges.');
}
Artisan::call('migrate', ['--force' => true]);
DB::transaction(function () use ($organizationId): void {
    $existing = Organization::whereKey($organizationId)->first();
    if ($existing) {
        if (($existing->settings['test_fixture'] ?? null) !== 'e106-role-ui') {
            throw new LogicException('Fixture organization collision.');
        }

        $disabled = User::where('email', 'team-e106-disabled@example.test')->first();
        $membership = $disabled ? Membership::withoutGlobalScopes()->where('organization_id', $existing->id)->where('user_id', $disabled->id)->first() : null;
        if ($disabled && (! $membership || $membership->role !== 'viewer' || $membership->status !== 'disabled')) {
            throw new LogicException('Fixture disabled user collision.');
        }
        if (! $disabled) {
            $brand = Brand::withoutGlobalScopes()->where('organization_id', $existing->id)->where('name', 'Пиротехника — Северные огни')->firstOrFail();
            $disabled = User::create(['name' => 'Тестовый отключённый', 'email' => 'team-e106-disabled@example.test', 'password' => 'test-only-password-123']);
            app(TenantContextRunner::class)->run(new TenantContext($existing->id, actorId: $existing->owner_user_id, source: 'job'), fn () => Membership::create(['user_id' => $disabled->id, 'role' => 'viewer', 'status' => 'disabled', 'brand_ids' => [$brand->id]]));
        }

        return;
    }
    if (User::whereIn('email', ['team-e106-owner@example.test', 'team-e106-viewer@example.test', 'team-e106-disabled@example.test'])->exists()) {
        throw new LogicException('Fixture user collision.');
    }
    $owner = User::create(['name' => 'Тестовый владелец', 'email' => 'team-e106-owner@example.test', 'password' => 'test-only-password-123']);
    $viewer = User::create(['name' => 'Тестовый наблюдатель', 'email' => 'team-e106-viewer@example.test', 'password' => 'test-only-password-123']);
    $disabled = User::create(['name' => 'Тестовый отключённый', 'email' => 'team-e106-disabled@example.test', 'password' => 'test-only-password-123']);
    $owner->forceFill(['email_verified_at' => now()])->save();
    $viewer->forceFill(['email_verified_at' => now()])->save();
    $organization = new Organization;
    $organization->forceFill(['id' => $organizationId, 'name' => 'Тестовая компания', 'settings' => ['test_fixture' => 'e106-role-ui']])->save();
    app(TenantContextRunner::class)->run(new TenantContext($organizationId, actorId: $owner->id, source: 'job'), function () use ($owner, $viewer, $disabled): void {
        $brand = Brand::create(['name' => 'Пиротехника — Северные огни', 'theme' => 'pyro']);
        Brand::create(['name' => 'Фейерверки — Второй бренд', 'theme' => 'neutral']);
        Membership::create(['user_id' => $owner->id, 'role' => 'owner']);
        Membership::create(['user_id' => $viewer->id, 'role' => 'viewer', 'brand_ids' => [$brand->id]]);
        Membership::create(['user_id' => $disabled->id, 'role' => 'viewer', 'status' => 'disabled', 'brand_ids' => [$brand->id]]);
        Invitation::create(['email' => 'team-e106-invite@example.test', 'role' => 'viewer', 'brand_ids' => [$brand->id], 'token_hash' => hash('sha256', str_repeat('A', 64)), 'invited_by' => $owner->id, 'expires_at' => now()->addDay()]);
    });
    $organization->forceFill(['owner_user_id' => $owner->id])->save();
});
fwrite(STDOUT, "Isolated Team browser fixture ready.\n");
