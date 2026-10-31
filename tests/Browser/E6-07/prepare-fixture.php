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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection()->getDatabaseName();
$orgId = getenv('E607_FIXTURE_ORG');
if (getenv('E607_FIXTURE_ENABLED') !== '1' || ! app()->environment('testing') || $db !== config('runtime.test_database') || $db === config('runtime.preview_database') || ! str_contains($db, '_test_') || ! is_string($orgId) || ! Str::isUlid($orgId) || $orgId !== app(Cabinet::class)->organizationId()) {
    throw new LogicException('Requires the explicitly enabled isolated test database and fixture organization.');
}
Artisan::call('migrate', ['--force' => true]);
DB::transaction(function () use ($orgId): void {
    $marker = 'SP-E6-07-browser-fixture-v1';
    $email = 'seller-limits-e607@example.test';
    $user = User::where('email', $email)->lockForUpdate()->first();
    if ($user && $user->name !== $marker) {
        throw new LogicException('Fixture user collision.');
    }
    $user ??= User::create(['name' => $marker, 'email' => $email, 'password' => 'test-only-password-123']);
    $user->forceFill(['password' => 'test-only-password-123', 'email_verified_at' => now(), 'mfa_secret' => null, 'mfa_confirmed_at' => null])->save();
    $org = Organization::whereKey($orgId)->lockForUpdate()->first();
    if ($org && (($org->settings['test_fixture'] ?? null) !== $marker || $org->owner_user_id !== $user->id)) {
        throw new LogicException('Fixture organization collision.');
    }
    if (! $org) {
        $org = new Organization;
        $org->forceFill(['id' => $orgId, 'name' => 'Тестовая компания', 'settings' => ['test_fixture' => $marker]])->save();
    }
    app(TenantContextRunner::class)->run(new TenantContext($orgId, actorId: $user->id, source: 'job'), function () use ($orgId, $user): void {
        if (! Membership::withoutGlobalScopes()->where('organization_id', $orgId)->where('user_id', $user->id)->exists()) {
            Membership::create(['user_id' => $user->id, 'role' => 'owner']);
        }
        if (! Brand::withoutGlobalScopes()->where('organization_id', $orgId)->where('name', 'Тестовый бренд')->exists()) {
            Brand::create(['name' => 'Тестовый бренд']);
        }
    });
    $org->forceFill(['owner_user_id' => $user->id])->save();
    $brand = Brand::withoutGlobalScopes()->where('organization_id', $orgId)->where('name', 'Тестовый бренд')->firstOrFail();
    if (Schema::hasTable('brand_modules')) {
        $where = ['organization_id' => $orgId, 'brand_id' => $brand->id, 'module' => 'seller'];
        $existing = DB::table('brand_modules')->where($where)->first();
        if ($existing) {
            DB::table('brand_modules')->where($where)->update(['enabled' => true, 'updated_at' => now()]);
        } else {
            DB::table('brand_modules')->insert([...$where, 'id' => (string) Str::ulid(), 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
    DB::table('notification_settings')->where('organization_id', $orgId)->where('brand_id', $brand->id)->where('user_id', $user->id)->delete();
    DB::table('seller_notification_rules')->where('organization_id', $orgId)->where('brand_id', $brand->id)->delete();
    DB::table('seller_digest_preferences')->where('organization_id', $orgId)->where('brand_id', $brand->id)->where('user_id', $user->id)->delete();
    DB::table('seller_test_cabinet_notifications')->where('organization_id', $orgId)->where('brand_id', $brand->id)->where('user_id', $user->id)->delete();
    DB::table('seller_test_deliveries')->where('organization_id', $orgId)->where('brand_id', $brand->id)->where('user_id', $user->id)->delete();
});
fwrite(STDOUT, "Isolated limits fixture ready.\n");
