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
use Tests\Support\MfaTestDatabase;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
MfaTestDatabase::assert(localFixture: true);
Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
$id = app(Cabinet::class)->organizationId();
if (Organization::whereKey($id)->exists() || User::where('email', 'mfa-recovery@example.test')->exists()) {
    throw new LogicException('Fixture collision.');
}
$user = User::create(['name' => 'Синтетический владелец', 'email' => 'mfa-recovery@example.test', 'password' => 'fixture-only-password']);
$user->forceFill(['email_verified_at' => now(), 'mfa_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'mfa_confirmed_at' => now()])->save();
$org = new Organization;
$org->forceFill(['id' => $id, 'name' => 'Тест MFA', 'owner_user_id' => $user->id])->save();
app(TenantContextRunner::class)->run(new TenantContext($id, actorId: $user->id), function () use ($user): void {
    Membership::create(['user_id' => $user->id, 'role' => 'owner']);
    Brand::create(['name' => 'Тестовый бренд', 'theme' => 'neutral', 'timezone' => 'Europe/Moscow']);
});
echo "Synthetic MFA fixture ready.\n";
