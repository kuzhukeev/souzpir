<?php

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Team\Models\Invitation;
use App\Tenancy\Cabinet;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

// Isolated browser fixtures only; abort before any migration on a different database or checkout.
putenv('APP_ENV=testing');
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection();
if (! app()->environment('testing') || ! str_ends_with(str_replace('\\', '/', base_path()), '/.worktrees/sp-invitation-safety')
    || $db->getDatabaseName() !== 'souzpir_test_sp_invitation_safety' || $db->getDatabaseName() !== config('runtime.test_database')
    || $db->getDatabaseName() === config('runtime.preview_database')) {
    throw new RuntimeException('Unsafe browser test environment');
}
$orgId = app(Cabinet::class)->organizationId();
if (($argv[1] ?? '') === 'setup') {
    Artisan::call('migrate:fresh', ['--force' => true]);
    $org = new Organization;
    $org->forceFill(['id' => $orgId, 'name' => 'Invitation browser fixture'])->save();
    $owner = User::create(['name' => 'Fixture owner', 'email' => 'owner-invite@example.test', 'password' => 'test-only-password-123']);
    $owner->forceFill(['email_verified_at' => now()])->save();
    $existing = User::create(['name' => 'Existing invited user', 'email' => 'existing-invite@example.test', 'password' => 'test-only-password-123']);
    $existing->forceFill(['email_verified_at' => now()])->save();
    app(TenantContextRunner::class)->run(new TenantContext($orgId, actorId: $owner->id), function () use ($owner, $existing): void {
        Membership::create(['user_id' => $owner->id, 'role' => 'owner']);
        foreach ([['new-invite@example.test', str_repeat('A', 64)], [$existing->email, str_repeat('B', 64)]] as [$email, $token]) {
            Invitation::create(['email' => $email, 'role' => 'member', 'brand_ids' => [], 'token_hash' => hash('sha256', $token), 'invited_by' => $owner->id, 'expires_at' => now()->addDay()]);
        }
    });
    $org->forceFill(['owner_user_id' => $owner->id])->save();
    echo 'ready';
} elseif (($argv[1] ?? '') === 'verification-url') {
    $user = User::where('email', 'new-invite@example.test')->firstOrFail();
    echo URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
} else {
    throw new InvalidArgumentException('Unknown fixture action');
}
