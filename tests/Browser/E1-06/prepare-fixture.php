<?php

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Team\Models\Invitation;
use App\Tenancy\Cabinet;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
if (in_array(getenv('E106_LAST_LOGIN_SCHEMA'), ['hide', 'restore'], true)) {
    $organization = Organization::whereKey($organizationId)->firstOrFail();
    if (($organization->settings['test_fixture'] ?? null) !== 'e106-role-ui') {
        throw new LogicException('Fixture organization collision.');
    }
    $hide = getenv('E106_LAST_LOGIN_SCHEMA') === 'hide';
    $source = $hide ? 'member_last_logins' : 'member_last_logins_e106_test_hidden';
    $target = $hide ? 'member_last_logins_e106_test_hidden' : 'member_last_logins';
    if (! Schema::hasTable($source) || Schema::hasTable($target)) {
        throw new LogicException('Unexpected last-login test schema state.');
    }
    DB::statement('ALTER TABLE '.$source.' RENAME TO '.$target);
    fwrite(STDOUT, "Last-login test schema changed.\n");
    exit;
}
if (in_array(getenv('E106_LAST_LOGIN_ACCESS'), ['disable', 'restore'], true)) {
    $organization = Organization::whereKey($organizationId)->firstOrFail();
    if (($organization->settings['test_fixture'] ?? null) !== 'e106-role-ui') {
        throw new LogicException('Fixture organization collision.');
    }
    $viewer = User::where('email', 'team-e106-viewer@example.test')->firstOrFail();
    $member = Membership::withoutGlobalScopes()->where('organization_id', $organizationId)
        ->where('user_id', $viewer->id)->where('role', 'viewer')->firstOrFail();
    $disabled = getenv('E106_LAST_LOGIN_ACCESS') === 'disable';
    if ($member->status !== ($disabled ? 'active' : 'disabled')) {
        throw new LogicException('Unexpected last-login viewer access state.');
    }
    DB::table('memberships')->where('id', $member->id)->update(['status' => $disabled ? 'disabled' : 'active']);
    fwrite(STDOUT, "Last-login test access changed.\n");
    exit;
}
if (getenv('E106_SHARED_MODULES') === '1') {
    $organization = Organization::whereKey($organizationId)->firstOrFail();
    if (($organization->settings['test_fixture'] ?? null) !== 'e106-role-ui') {
        throw new LogicException('Fixture organization collision.');
    }
    foreach (['Пиротехника — Северные огни' => 'seller', 'Фейерверки — Второй бренд' => 'vision'] as $name => $module) {
        $brand = Brand::withoutGlobalScopes()->where('organization_id', $organizationId)->where('name', $name)->firstOrFail();
        DB::table('brand_modules')->updateOrInsert(
            ['organization_id' => $organizationId, 'brand_id' => $brand->id, 'module' => $module],
            ['id' => (string) Str::ulid(), 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        );
    }
    fwrite(STDOUT, "Isolated shared modules configured.\n");
    exit;
}
if (getenv('E106_EXPIRING_INVITATION') === '1') {
    $organization = Organization::whereKey($organizationId)->firstOrFail();
    if (($organization->settings['test_fixture'] ?? null) !== 'e106-role-ui') {
        throw new LogicException('Fixture organization collision.');
    }
    $email = 'team-e106-expiring-'.Str::lower(Str::random(12)).'@example.test';
    app(TenantContextRunner::class)->run(new TenantContext($organizationId, actorId: $organization->owner_user_id, source: 'job'), function () use ($organization, $email): void {
        $brand = Brand::withoutGlobalScopes()->where('organization_id', $organization->id)->where('name', 'Пиротехника — Северные огни')->firstOrFail();
        Invitation::create(['email' => $email, 'role' => 'viewer', 'brand_ids' => [$brand->id], 'token_hash' => hash('sha256', Str::random(64)), 'invited_by' => $organization->owner_user_id, 'expires_at' => now()->addSeconds(12)]);
    });
    fwrite(STDOUT, json_encode(['email' => $email], JSON_THROW_ON_ERROR));
    exit;
}
if (getenv('E106_ACTIVITY_FIXTURE') === '1') {
    $organization = Organization::whereKey($organizationId)->firstOrFail();
    if (($organization->settings['test_fixture'] ?? null) !== 'e106-role-ui') {
        throw new LogicException('Fixture organization collision.');
    }
    $viewer = User::where('email', 'team-e106-viewer@example.test')->firstOrFail();
    $viewerMembership = Membership::withoutGlobalScopes()->where('organization_id', $organizationId)->where('user_id', $viewer->id)->firstOrFail();
    $ownerMembership = Membership::withoutGlobalScopes()->where('organization_id', $organizationId)->where('user_id', $organization->owner_user_id)->firstOrFail();
    $brand = Brand::withoutGlobalScopes()->where('organization_id', $organizationId)->where('name', 'Пиротехника — Северные огни')->firstOrFail();
    $otherBrand = Brand::withoutGlobalScopes()->where('organization_id', $organizationId)->where('name', 'Фейерверки — Второй бренд')->firstOrFail();
    $tag = Str::lower(Str::random(10));
    $events = [
        [new TenantContext($organizationId, $brand->id, $viewer->id, $viewerMembership->id, 'job'), 'activity-target-'.$tag],
        [new TenantContext($organizationId, null, $viewer->id, $viewerMembership->id, 'job'), 'activity-global-'.$tag],
        [new TenantContext($organizationId, $otherBrand->id, $viewer->id, $viewerMembership->id, 'job'), 'activity-other-brand-'.$tag],
        [new TenantContext($organizationId, $brand->id, $organization->owner_user_id, $ownerMembership->id, 'job'), 'activity-other-actor-'.$tag],
    ];
    DB::transaction(function () use ($events): void {
        foreach ($events as [$context, $subject]) {
            // Historical audit can outlive the actor's access to a brand.
            app(AuditWriter::class)->record($context, new AuditEntry('team.activity_test', 'user', $subject));
        }
    });
    fwrite(STDOUT, json_encode(['actor' => $viewer->id, 'tag' => $tag], JSON_THROW_ON_ERROR));
    exit;
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
