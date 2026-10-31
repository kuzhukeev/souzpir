<?php

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Cabinet;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$expectedBase = realpath(__DIR__.'/../../..');
$dbName = DB::connection()->getDatabaseName();
$orgId = getenv('E107_FIXTURE_ORG');
if (getenv('E107_FIXTURE_ENABLED') !== '1' || ! app()->environment('testing') || realpath(base_path()) !== $expectedBase || basename(dirname($expectedBase)) !== '.worktrees' || basename($expectedBase) !== 'sp-e1-07' || $dbName !== 'souzpir_test_sp_e1_07' || $dbName !== config('runtime.test_database') || $dbName === config('runtime.preview_database') || config('database.default') !== 'pgsql' || config('database.connections.pgsql.host') !== '127.0.0.1' || (string) config('database.connections.pgsql.port') !== '55607' || ! is_string($orgId) || ! Str::isUlid($orgId) || $orgId !== app(Cabinet::class)->organizationId()) {
    throw new LogicException('Requires the explicitly enabled isolated SP-E1-07 worktree and test database.');
}
$connection = DB::selectOne('select current_database() as db, current_user as role, rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls from pg_roles where rolname = current_user');
if ($connection->db !== $dbName || $connection->role !== 'souzpir_sp_e1_07' || $connection->rolsuper || $connection->rolcreatedb || $connection->rolcreaterole || $connection->rolreplication || $connection->rolbypassrls) {
    throw new LogicException('Unexpected PostgreSQL connection.');
}
if (Artisan::call('migrate', ['--force' => true]) !== 0) {
    throw new RuntimeException('Test migrations failed.');
}

$manifest = DB::transaction(function () use ($orgId): array {
    $marker = 'SP-E1-07-browser-fixture-v1';
    $email = 'billing-e107-owner@example.test';
    $user = User::where('email', $email)->lockForUpdate()->first();
    if ($user && ($user->name !== 'Тестовый владелец' || DB::table('memberships')->where('user_id', $user->id)->where('organization_id', '!=', $orgId)->exists())) {
        throw new LogicException('Fixture user collision.');
    }
    $user ??= User::create(['name' => 'Тестовый владелец', 'email' => $email, 'password' => 'test-only-password-123']);
    $user->forceFill(['email_verified_at' => now(), 'terms_accepted_at' => now(), 'ai_consent_at' => now()])->save();

    $organization = Organization::whereKey($orgId)->lockForUpdate()->first();
    if ($organization && (($organization->settings['test_fixture'] ?? null) !== $marker || $organization->owner_user_id !== $user->id)) {
        throw new LogicException('Fixture organization collision.');
    }
    if (! $organization) {
        $organization = new Organization;
        $organization->forceFill(['id' => $orgId, 'name' => 'Тестовая организация', 'settings' => ['test_fixture' => $marker]])->save();
    }
    $brands = [];
    app(TenantContextRunner::class)->run(new TenantContext($orgId, actorId: $user->id, source: 'job'), function () use ($orgId, $user, $marker, &$brands): void {
        foreach (['neutral' => 'Тестовый основной бренд', 'pyro' => 'Тестовая пиротехника и праздничные огни', 'confectionery' => 'Тестовая кондитерская'] as $theme => $name) {
            $brand = Brand::withoutGlobalScopes()->where('organization_id', $orgId)->where('name', $name)->first();
            if ($brand && ($brand->settings['test_fixture'] ?? null) !== $marker) {
                throw new LogicException('Fixture brand collision.');
            }
            $brand ??= Brand::create(['name' => $name, 'theme' => $theme, 'status' => 'active', 'settings' => ['test_fixture' => $marker]]);
            $brands[$theme] = ['id' => $brand->id, 'name' => $brand->name];
        }
        if (! Membership::withoutGlobalScopes()->where('organization_id', $orgId)->where('user_id', $user->id)->exists()) {
            Membership::create(['user_id' => $user->id, 'role' => 'owner', 'brand_ids' => []]);
        }
    });
    $organization->forceFill(['owner_user_id' => $user->id])->save();

    return ['owner_email' => $email, 'brands' => $brands];
});
file_put_contents(base_path('.local/runtime/e1-07-browser.json'), json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
fwrite(STDOUT, "Isolated billing fixture ready.\n");
