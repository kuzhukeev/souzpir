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
if (getenv('E108_FIXTURE_ENABLED') !== '1' || ! app()->environment('testing') || $db !== 'souzpir_test_sp_e1_08' || $db !== config('runtime.test_database') || $db === config('runtime.preview_database')) {
    throw new LogicException('Refusing fixture outside explicitly enabled isolated E1-08 testing database.');
}
Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
DB::transaction(function (): void {
    $marker = 'SP-E1-08-browser-fixture-v1';
    $orgId = app(Cabinet::class)->organizationId();
    $org = Organization::whereKey($orgId)->lockForUpdate()->first();
    if ($org && ($org->settings['test_fixture'] ?? null) !== $marker) {
        throw new LogicException('Fixture organization collision.');
    }
    $owner = $org ? User::findOrFail($org->owner_user_id) : User::where('email', 'profile-e108@example.test')->first();
    $other = User::where('email', 'profile-e108-other@example.test')->first();
    if (! $org && ($owner || $other)) {
        throw new LogicException('Fixture user collision.');
    }
    $owner ??= User::create(['name' => 'Проверка профиля', 'email' => 'profile-e108@example.test', 'password' => 'test-only-password-123']);
    $other ??= User::create(['name' => 'Другой участник', 'email' => 'profile-e108-other@example.test', 'password' => 'test-only-password-123']);
    if ($org && DB::table('memberships')->where('organization_id', $orgId)->whereNotIn('user_id', [$owner->id, $other->id])->exists()) {
        throw new LogicException('Fixture has non-synthetic memberships.');
    }
    foreach ([$owner, $other] as $user) {
        $user->forceFill(['password' => 'test-only-password-123', 'email_verified_at' => now(), 'mfa_secret' => null, 'mfa_confirmed_at' => null, 'mfa_last_counter' => null, 'auth_version' => 0, 'remember_token' => Str::random(60)])->save();
        foreach (['profile_preferences', 'profile_email_changes', 'profile_test_notifications', 'notification_settings', 'auth_session_registry', 'sessions'] as $table) {
            DB::table($table)->where('user_id', $user->id)->delete();
        }
    }
    $owner->forceFill(['name' => 'Проверка профиля', 'email' => 'profile-e108@example.test', 'phone' => null])->save();
    $org ??= new Organization;
    $org->forceFill(['id' => $orgId, 'name' => 'Тестовая компания профиля', 'owner_user_id' => $owner->id, 'settings' => ['test_fixture' => $marker]])->save();
    app(TenantContextRunner::class)->run(new TenantContext($orgId, actorId: $owner->id, source: 'job'), function () use ($owner, $other, $orgId): void {
        foreach ([[$owner, 'owner'], [$other, 'admin']] as [$user, $role]) {
            if (! Membership::where('user_id', $user->id)->exists()) {
                Membership::create(['user_id' => $user->id, 'role' => $role]);
            }
        }
        $brand = Brand::first() ?? Brand::create(['name' => 'Пиротехника · тест личных настроек', 'theme' => 'neutral', 'timezone' => 'Europe/Moscow']);
        foreach (['seller', 'vision'] as $module) {
            if (! DB::table('brand_modules')->where('brand_id', $brand->id)->where('module', $module)->exists()) {
                DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $orgId, 'brand_id' => $brand->id, 'module' => $module, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    });
    DB::table('audit_logs')->where('organization_id', $orgId)->whereIn('actor_id', [$owner->id, $other->id])->delete();
});
fwrite(STDOUT, "Synthetic E1-08 browser fixture ready; no external deliveries.\n");
