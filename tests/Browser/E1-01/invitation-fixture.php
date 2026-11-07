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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

// Isolated browser fixtures only; abort before any migration on a different database or checkout.
putenv('APP_ENV=testing');
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection();
$path = str_replace('\\', '/', base_path());
$dedicatedWorktree = (bool) preg_match('#/\.worktrees/[a-z0-9-]+$#', $path);
$testDatabase = getenv('INVITATION_BROWSER_TEST_DATABASE') ?: ($dedicatedWorktree ? config('runtime.test_database') : 'souzpir_test_sp_invitation_safety');
$ciFixture = getenv('INVITATION_BROWSER_CI_FIXTURE') === '1'
    && getenv('CI') === 'true' && realpath(base_path()) === realpath(getcwd())
    && config('database.connections.pgsql.host') === '127.0.0.1'
    && (int) config('database.connections.pgsql.port') === 5432
    && config('database.connections.pgsql.username') === 'sp_invitation_ci_app'
    && $testDatabase === 'souzpir_test_sp_invitation_ci';
if ($ciFixture) {
    $actual = DB::selectOne('select current_database() as db, current_user as role, inet_server_addr() as host, inet_server_port() as port, rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls from pg_roles where rolname = current_user');
    $ciFixture = $actual && $actual->db === $testDatabase && $actual->role === 'sp_invitation_ci_app'
        && $actual->host === '127.0.0.1' && (int) $actual->port === 5432 && ! $actual->rolsuper
        && ! $actual->rolcreatedb && ! $actual->rolcreaterole && ! $actual->rolreplication && ! $actual->rolbypassrls;
}
$explicitMainFixture = getenv('INVITATION_BROWSER_MAIN_FIXTURE') === '1'
    && realpath(base_path()) === realpath(getcwd()) && is_dir(base_path('.git'))
    && config('database.connections.pgsql.host') === '127.0.1.48'
    && config('database.connections.pgsql.username') === 'souzpir_sp_invitation_safety'
    && DB::selectOne('select current_user as role')->role === 'souzpir_sp_invitation_safety';
if (! app()->environment('testing') || (! $dedicatedWorktree && ! $explicitMainFixture && ! $ciFixture)
    || ! preg_match('/^souzpir_test_[a-z0-9_]+$/D', $testDatabase)
    || $db->getDatabaseName() !== $testDatabase || $db->getDatabaseName() !== config('runtime.test_database')
    || $db->getDatabaseName() === config('runtime.preview_database')) {
    throw new RuntimeException('Unsafe browser test environment');
}
$orgId = app(Cabinet::class)->organizationId();
if (($argv[1] ?? '') === 'setup') {
    if (config('cache.default') !== 'file' || ! str_starts_with(str_replace('\\', '/', storage_path()), str_replace('\\', '/', base_path('.local/runtime/testing')))) {
        throw new RuntimeException('Browser test cache is not isolated.');
    }
    Cache::flush();
    Artisan::call('migrate:fresh', ['--force' => true]);
    $org = new Organization;
    $org->forceFill(['id' => $orgId, 'name' => 'Invitation browser fixture'])->save();
    $owner = User::create(['name' => 'Fixture owner', 'email' => 'owner-invite@example.test', 'password' => 'test-only-password-123']);
    $owner->forceFill(['email_verified_at' => now()])->save();
    $existing = User::create(['name' => 'Existing invited user', 'email' => 'existing-invite@example.test', 'password' => 'test-only-password-123']);
    $existing->forceFill(['email_verified_at' => now()])->save();
    app(TenantContextRunner::class)->run(new TenantContext($orgId, actorId: $owner->id), function () use ($owner, $existing): void {
        Membership::create(['user_id' => $owner->id, 'role' => 'owner']);
        foreach ([['new-invite@example.test', str_repeat('A', 64), false], [$existing->email, str_repeat('B', 64), false],
            ['retry-invite@example.test', str_repeat('C', 64), false], ['older-invite@example.test', str_repeat('D', 64), false],
            ['newer-invite@example.test', str_repeat('E', 64), false], ['revoked-invite@example.test', str_repeat('R', 64), true]] as [$email, $token, $revoked]) {
            $invite = Invitation::create(['email' => $email, 'role' => 'member', 'brand_ids' => [], 'token_hash' => hash('sha256', $token), 'invited_by' => $owner->id, 'expires_at' => now()->addDay()]);
            if ($revoked) {
                $invite->forceFill(['revoked_at' => now()])->save();
            }
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
