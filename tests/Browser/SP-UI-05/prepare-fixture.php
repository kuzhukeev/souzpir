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
if (getenv('SP_UI_05_FIXTURE') !== '1' || ! app()->environment('testing') || basename(base_path()) !== 'sp-ui-05'
    || DB::connection()->getDatabaseName() !== 'souzpir_test_sp_ui05'
    || DB::connection()->getConfig('host') !== '127.0.0.1' || (string) DB::connection()->getConfig('port') !== '55989'
    || DB::connection()->getConfig('username') !== 'sp_ui05_app' || config('runtime.test_database') !== 'souzpir_test_sp_ui05'
    || config('runtime.preview_database') === 'souzpir_test_sp_ui05') {
    throw new LogicException('Требуется отдельная тестовая база SP-UI-05.');
}
Artisan::call('migrate', ['--force' => true]);
if (DB::table('users')->exists() || DB::table('organizations')->exists()) {
    throw new LogicException('Тестовая база должна быть пустой.');
}
$owner = User::create(['name' => 'Тестовый владелец', 'email' => 'owner-ui05@example.test', 'password' => 'test-only-password-123']);
$owner->forceFill(['email_verified_at' => now(), 'terms_accepted_at' => now(), 'ai_consent_at' => now()])->save();
$organization = new Organization;
$organization->forceFill(['id' => '01J00000000000000000000000', 'name' => 'Тестовая организация', 'owner_user_id' => $owner->id])->save();
$brands = [];
app(TenantContextRunner::class)->run(new TenantContext($organization->id, actorId: $owner->id, source: 'job'), function () use ($owner, $organization, &$brands): void {
    foreach (['neutral' => 'Основной бренд', 'pyro' => 'Пиротехника', 'confectionery' => 'Кондитерская'] as $theme => $name) {
        $brand = Brand::create(['name' => $name, 'theme' => $theme]);
        $brands[$theme] = $brand->id;
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }
    Membership::create(['user_id' => $owner->id, 'role' => 'owner', 'brand_ids' => []]);
});
file_put_contents(base_path('.local/runtime/ui05-fixture.json'), json_encode($brands, JSON_THROW_ON_ERROR));
