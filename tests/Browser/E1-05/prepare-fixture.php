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
$db = DB::connection()->getDatabaseName();
$orgId = getenv('E105_FIXTURE_ORG');
if (getenv('E105_FIXTURE_ENABLED') !== '1' || ! app()->environment('testing') || $db !== config('runtime.test_database') || $db === config('runtime.preview_database') || ! str_contains($db, '_test_') || ! is_string($orgId) || ! Str::isUlid($orgId) || $orgId !== app(Cabinet::class)->organizationId()) {
    throw new LogicException('Refusing fixture outside explicitly enabled isolated testing environment.');
}
if (getenv('CI') === 'true') {
    $state = DB::selectOne('select current_database() as db, current_user as role, inet_server_addr() as host, inet_server_port() as port, rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls from pg_roles where rolname = current_user');
    if (realpath(base_path()) !== realpath(getcwd()) || config('database.connections.pgsql.url')
        || $db !== 'souzpir_test_sp_e1_05' || ! $state || $state->db !== $db || $state->role !== 'sp_e1_05_app'
        || $state->host !== '127.0.0.1' || (int) $state->port !== 5432 || $state->rolsuper || $state->rolcreatedb
        || $state->rolcreaterole || $state->rolreplication || $state->rolbypassrls) {
        throw new LogicException('Refusing an unexpected CI brand fixture connection.');
    }
}
Artisan::call('migrate', ['--force' => true]);
DB::transaction(function () use ($orgId): void {
    $marker = 'SP-E1-05-browser-fixture-v1';
    $brandId = '01J00000000000000000000004';
    $owner = User::where('email', 'brands-e105-owner@example.test')->lockForUpdate()->first();
    if ($owner && $owner->name !== $marker.' owner') {
        throw new LogicException('Fixture user collision.');
    }
    $org = Organization::whereKey($orgId)->lockForUpdate()->first();
    if ($org && (($org->settings['test_fixture'] ?? null) !== $marker || ! $owner || $org->owner_user_id !== $owner->id)) {
        throw new LogicException('Fixture organization collision.');
    }
    $owner ??= User::create(['name' => $marker.' owner', 'email' => 'brands-e105-owner@example.test', 'password' => 'test-only-password-123']);
    $owner->forceFill(['password' => 'test-only-password-123', 'email_verified_at' => now()])->save();
    if (! $org) {
        $org = new Organization;
        $org->forceFill(['id' => $orgId, 'name' => 'Тестовая компания', 'settings' => ['test_fixture' => $marker]])->save();
    }
    app(TenantContextRunner::class)->run(new TenantContext($orgId, actorId: $owner->id, source: 'job'), function () use ($owner, $orgId, $brandId): void {
        $membership = Membership::withoutGlobalScopes()->where('organization_id', $orgId)->where('user_id', $owner->id)->first();
        if (! $membership) {
            Membership::create(['user_id' => $owner->id, 'role' => 'owner', 'brand_ids' => []]);
        } elseif ($membership->role !== 'owner' || $membership->status !== 'active') {
            throw new LogicException('Fixture membership collision.');
        }
        $brand = Brand::withoutGlobalScopes()->whereKey($brandId)->first();
        if ($brand && $brand->organization_id !== $orgId) {
            throw new LogicException('Fixture brand collision.');
        }
        $brand ??= new Brand;
        $brand->forceFill(['id' => $brandId, 'organization_id' => $orgId, 'name' => 'Тестовый бренд', 'website' => null, 'industry' => null, 'market' => null, 'language' => 'ru', 'timezone' => 'Europe/Moscow', 'theme' => 'neutral', 'status' => 'active', 'settings' => ['aliases' => [], 'theme' => ['subtitle' => '', 'promo_card' => true]]])->save();
        DB::table('brand_domains')->where('organization_id', $orgId)->where('brand_id', $brandId)->delete();
        foreach (['seller', 'vision'] as $module) {
            $id = DB::table('brand_modules')->where('brand_id', $brandId)->where('module', $module)->value('id');
            DB::table('brand_modules')->updateOrInsert(['brand_id' => $brandId, 'module' => $module], ['id' => $id ?? (string) Str::ulid(), 'organization_id' => $orgId, 'enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
        }
    });
    $org->forceFill(['owner_user_id' => $owner->id])->save();
});
fwrite(STDOUT, "Isolated synthetic brand fixture ready.\n");
