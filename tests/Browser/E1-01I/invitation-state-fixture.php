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
app(TenantContextRunner::class)->run(new TenantContext($organization, actorId: $owner->id), function () use ($organization, $owner, $brand): void {
    $states = [
        ['state-expired@example.test', str_repeat('X', 64), ['expires_at' => now()->subDay()]],
        ['state-revoked@example.test', str_repeat('Y', 64), ['revoked_at' => now()]],
        ['state-accepted@example.test', str_repeat('Z', 64), ['accepted_at' => now(), 'accepted_by' => $owner->id]],
        ['state-mismatch@example.test', str_repeat('M', 64), []],
    ];
    foreach ($states as [$email, $token, $after]) {
        $existing = Invitation::withoutGlobalScopes()->where('organization_id', $organization)->where('email', $email)->first();
        $invite = $existing ?? Invitation::create(['email' => $email, 'role' => 'member', 'brand_ids' => [$brand->id],
            'token_hash' => hash('sha256', $token), 'invited_by' => $owner->id, 'expires_at' => now()->addDays(7)]);
        // Reset a previous run's state directly: Eloquent guards every field here against mutation once set.
        DB::table('invitations')->where('id', $invite->id)->update([
            'token_hash' => hash('sha256', $token), 'exchanged_at' => null, 'accepted_at' => null, 'accepted_by' => null, 'revoked_at' => null,
            'expires_at' => $after['expires_at'] ?? now()->addDays(7), 'updated_at' => now(),
        ]);
        DB::table('invitation_exchange_sessions')->where('invitation_id', $invite->id)->delete();
        if (isset($after['accepted_at'])) {
            DB::table('invitations')->where('id', $invite->id)->update(['accepted_at' => $after['accepted_at'], 'accepted_by' => $after['accepted_by']]);
        } elseif (isset($after['revoked_at'])) {
            DB::table('invitations')->where('id', $invite->id)->update(['revoked_at' => $after['revoked_at']]);
        }
    }
    if (! Membership::withoutGlobalScopes()->where('organization_id', $organization)->where('user_id', $owner->id)->exists()) {
        Membership::create(['user_id' => $owner->id, 'role' => 'owner']);
    }
});
echo 'ready';
