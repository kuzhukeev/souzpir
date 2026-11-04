<?php

use App\Models\Brand;
use App\Models\Membership;
use App\Models\User;
use App\Shell\Team\Models\Invitation;
use App\Tenancy\Cabinet;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

putenv('APP_ENV=testing');
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== config('runtime.test_database')
    || ! preg_match('/^souzpir_test_[a-z0-9_]+$/D', DB::connection()->getDatabaseName())
    || DB::connection()->getDatabaseName() === config('runtime.preview_database')) {
    throw new RuntimeException('Isolated test database required.');
}
$organization = app(Cabinet::class)->organizationId();
$owner = User::where('email', 'browser-auth@example.test')->firstOrFail();
$brand = Brand::withoutGlobalScopes()->where('organization_id', $organization)->firstOrFail();
$verifyUser = User::firstOrCreate(['email' => 'verify-browser@example.test'], ['name' => 'Verify Browser', 'password' => 'test-only-password-123']);
app(TenantContextRunner::class)->run(new TenantContext($organization, actorId: $owner->id), function () use ($organization, $verifyUser): void {
    if (! Membership::withoutGlobalScopes()->where('organization_id', $organization)->where('user_id', $verifyUser->id)->exists()) {
        Membership::create(['user_id' => $verifyUser->id, 'role' => 'member']);
    }
});
app(TenantContextRunner::class)->run(new TenantContext($organization, actorId: $owner->id), function () use ($organization, $owner, $brand): void {
    $existing = Invitation::withoutGlobalScopes()->where('organization_id', $organization)->where('email', 'browser-invite@example.test')->first();
    if ($existing) {
        DB::table('invitations')->where('id', $existing->id)->update(['exchanged_at' => null,
            'settings' => json_encode(['sales_manager' => true], JSON_THROW_ON_ERROR)]);

        return;
    }
    Invitation::create(['email' => 'browser-invite@example.test', 'role' => 'member', 'brand_ids' => [$brand->id], 'settings' => ['sales_manager' => true],
        'token_hash' => hash('sha256', str_repeat('A', 64)), 'invited_by' => $owner->id, 'expires_at' => now()->addDays(7)]);
});
