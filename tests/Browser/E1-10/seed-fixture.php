<?php

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/runtime-guard.php';
Artisan::call('migrate', ['--force' => true]);
$orgId = '01J00000000000000000000000';
if (Organization::whereKey($orgId)->exists() || User::where('email', 'owner-e110@example.test')->exists()) {
    throw new LogicException('Synthetic fixture already exists.');
}
DB::transaction(function () use ($orgId): void {
    $owner = User::create(['name' => 'Тестовый владелец', 'email' => 'owner-e110@example.test', 'password' => 'test-only-password-123']);
    $owner->forceFill(['email_verified_at' => now()])->save();
    $org = new Organization;
    $org->forceFill(['id' => $orgId, 'name' => 'Тестовая организация', 'owner_user_id' => $owner->id])->save();
    app(TenantContextRunner::class)->run(new TenantContext($orgId, actorId: $owner->id, source: 'job'), function () use ($owner): void {
        Membership::create(['user_id' => $owner->id, 'role' => 'owner']);
        Brand::create(['name' => 'Альфа', 'theme' => 'neutral']);
        Brand::create(['name' => 'Бета', 'theme' => 'pyro']);
    });
});
echo "Synthetic browser fixture ready.\n";
