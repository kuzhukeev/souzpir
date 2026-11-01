<?php

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (getenv('SP_E106G_FIXTURE') !== '1' || ! app()->environment('testing') || basename(base_path()) !== 'sp-e1-06g'
    || DB::connection()->getDatabaseName() !== 'souzpir_test_sp_e106g' || DB::connection()->getConfig('host') !== '127.0.0.1'
    || (string) DB::connection()->getConfig('port') !== '55990' || DB::connection()->getConfig('username') !== 'sp_e106g_app'
    || config('runtime.test_database') !== 'souzpir_test_sp_e106g' || config('runtime.preview_database') === 'souzpir_test_sp_e106g') {
    throw new LogicException('Требуется отдельная тестовая база SP-E1-06G.');
}
Artisan::call('migrate', ['--force' => true]);
if (DB::table('users')->exists() || DB::table('organizations')->exists()) {
    throw new LogicException('Тестовая база должна быть пустой.');
}
$owner = User::create(['name' => 'Владелец', 'email' => 'owner-e106g@example.test', 'password' => 'test-only-password-123']);
$viewer = User::create(['name' => 'Наблюдатель', 'email' => 'viewer-e106g@example.test', 'password' => 'test-only-password-123']);
foreach ([$owner, $viewer] as $user) {
    $user->forceFill(['email_verified_at' => now(), 'terms_accepted_at' => now(), 'ai_consent_at' => now()])->save();
}
$organization = new Organization;
$organization->forceFill(['id' => '01J00000000000000000000000', 'name' => 'Тестовая организация', 'owner_user_id' => $owner->id])->save();
app(TenantContextRunner::class)->run(new TenantContext($organization->id, actorId: $owner->id, source: 'job'), function () use ($owner, $viewer, $organization): void {
    $brand = Brand::create(['name' => 'Тестовый бренд']);
    Membership::create(['user_id' => $owner->id, 'role' => 'owner', 'brand_ids' => []]);
    Membership::create(['user_id' => $viewer->id, 'role' => 'viewer', 'brand_ids' => [$brand->id]]);
    DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
});
