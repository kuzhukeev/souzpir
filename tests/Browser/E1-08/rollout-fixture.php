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
use Tests\Support\MfaTestDatabase;

// This synthetic app directory has its own copy of vendor and runtime. The
// checks precede migrate:fresh and prohibit the real checkout and preview DB.
$root = realpath(__DIR__.'/../../../.local/tmp/rollout-synthetic');
if (! is_string($root) || getcwd() !== $root || getenv('MFA_LOCAL_FIXTURE') !== '1') {
    throw new LogicException('Wrong rollout fixture directory.');
}
require $root.'/vendor/autoload.php';
require_once __DIR__.'/../../Support/MfaTestDatabase.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
MfaTestDatabase::assert(localFixture: true);
if (config('session.driver') !== 'file' || config('cache.default') !== 'file') {
    throw new LogicException('The rollout exercise requires file sessions and file cache.');
}
$mode = $argv[1] ?? '';
if ($mode === 'seed') {
    Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
    $id = app(Cabinet::class)->organizationId();
    $user = User::create(['name' => 'Synthetic rollout owner', 'email' => 'rollout-owner@example.test', 'password' => 'fixture-only-password']);
    $user->forceFill(['email_verified_at' => now(), 'mfa_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'mfa_confirmed_at' => now()])->save();
    $org = new Organization;
    $org->forceFill(['id' => $id, 'name' => 'Synthetic rollout', 'owner_user_id' => $user->id])->save();
    app(TenantContextRunner::class)->run(new TenantContext($id, actorId: $user->id), function () use ($user): void {
        Membership::create(['user_id' => $user->id, 'role' => 'owner']);
        Brand::create(['name' => 'Synthetic rollout brand', 'theme' => 'neutral', 'timezone' => 'Europe/Moscow']);
    });
} elseif ($mode === 'migrate') {
    $migrationDir = realpath(__DIR__.'/../../../database/migrations/E1-08');
    if (! is_string($migrationDir)) {
        throw new LogicException('E1-08 migration path unavailable.');
    }
    Artisan::call('migrate', ['--path' => $migrationDir, '--realpath' => true, '--force' => true, '--no-interaction' => true]);
} elseif ($mode === 'write') {
    $user = User::where('email', 'rollout-owner@example.test')->firstOrFail();
    DB::table('profile_preferences')->updateOrInsert(['user_id' => $user->id], ['timezone' => 'Europe/Moscow', 'language' => 'ru', 'created_at' => now(), 'updated_at' => now()]);
} elseif ($mode === 'verify') {
    $user = User::where('email', 'rollout-owner@example.test')->firstOrFail();
    if (DB::table('profile_preferences')->where('user_id', $user->id)->value('timezone') !== 'Europe/Moscow') {
        throw new LogicException('Profile data did not survive rollout.');
    }
} else {
    throw new LogicException('Unknown mode.');
}
echo "Synthetic rollout fixture: {$mode} passed.\n";
