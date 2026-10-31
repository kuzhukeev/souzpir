<?php

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Cabinet;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// Explicit opt-in and two independent checks of the real connection precede every write.
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' || getenv('E802_FIXTURE_ENABLED') !== '1') {
    throw new LogicException('Fixture requires an explicitly enabled isolated testing process.');
}
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Http::preventStrayRequests();
$root = str_replace('\\', '/', realpath(__DIR__.'/../../..'));
$storage = str_replace('\\', '/', storage_path());
$state = DB::selectOne('select current_database() as db, current_user as role, inet_server_addr() as host, inet_server_port() as port, rolsuper, rolcreatedb, rolcreaterole from pg_roles where rolname = current_user');
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'souzpir_test_sp_e8_02'
    || config('runtime.test_database') !== 'souzpir_test_sp_e8_02' || config('runtime.preview_database') === 'souzpir_test_sp_e8_02'
    || ! $state || $state->db !== 'souzpir_test_sp_e8_02' || $state->role !== 'sp_e8_02_app'
    || $state->host !== '127.0.0.1' || (int) $state->port !== 55490 || $state->rolsuper || $state->rolcreatedb || $state->rolcreaterole
    || ! str_starts_with($storage.'/', $root.'/.local/runtime/testing/storage/')
    || config('runtime.ai_driver') !== 'fake' || config('runtime.delivery_driver') !== 'fake'
    || ! in_array(config('mail.default'), ['log', 'array'], true)) {
    throw new LogicException('Refusing fixture outside isolated E8-02 database, storage and fake adapters.');
}

// This fixture never cleans or replaces any data. Reruns require a fresh isolated test DB.
DB::transaction(function (): void {
    $orgId = app(Cabinet::class)->organizationId();
    if (DB::table('organizations')->exists() || DB::table('users')->exists()) {
        throw new LogicException('Fixture expects a fresh isolated database; existing data is preserved.');
    }
    $owner = User::create(['name' => 'Владелец теста Видения', 'email' => 'vision-e802@example.test', 'password' => 'test-only-password-123']);
    $other = User::create(['name' => 'Редактор другого бренда', 'email' => 'vision-e802-other@example.test', 'password' => 'test-only-password-123']);
    foreach ([$owner, $other] as $user) {
        $user->forceFill(['email_verified_at' => now()])->save();
    }
    $organization = new Organization;
    $organization->forceFill(['id' => $orgId, 'name' => 'Тестовая компания Видения', 'status' => 'active', 'owner_user_id' => $owner->id,
        'settings' => ['test_fixture' => 'SP-E8-02-browser-fixture-v1']])->save();
    app(TenantContextRunner::class)->run(new TenantContext($orgId, actorId: $owner->id, source: 'job'), function () use ($owner, $other, $orgId): void {
        $first = Brand::create(['name' => 'Тест Видения', 'website' => 'https://vision-example.test', 'theme' => 'neutral', 'timezone' => 'Europe/Moscow']);
        $second = Brand::create(['name' => 'Другой тестовый бренд', 'website' => 'https://other-vision.example.test', 'theme' => 'pyro', 'timezone' => 'Europe/Moscow']);
        // Explicit grants make the two login contexts disjoint even though both are in the one cabinet organization.
        Membership::create(['user_id' => $owner->id, 'role' => 'owner', 'brand_ids' => [$first->id]]);
        Membership::create(['user_id' => $other->id, 'role' => 'member', 'brand_ids' => [$second->id], 'settings' => ['sales_manager' => false]]);
        foreach ([$first, $second] as $brand) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $orgId, 'brand_id' => $brand->id,
                'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    });
});
fwrite(STDOUT, "Isolated synthetic E8-02 browser fixture ready; no network calls.\n");
