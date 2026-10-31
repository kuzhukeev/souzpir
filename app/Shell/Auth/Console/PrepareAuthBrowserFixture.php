<?php

namespace App\Shell\Auth\Console;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Cabinet;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

final class PrepareAuthBrowserFixture extends Command
{
    protected $signature = 'auth:test-browser-fixture';

    protected $description = 'Prepare synthetic authentication fixture in the isolated test database';

    public function handle(): int
    {
        if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== config('runtime.test_database') || DB::connection()->getDatabaseName() === config('runtime.preview_database')) {
            $this->error('Test-only fixture requires an isolated test database.');

            return self::FAILURE;
        }
        Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
        $organizationId = app(Cabinet::class)->organizationId();
        $organization = Organization::find($organizationId);
        if (! $organization) {
            $organization = new Organization;
            $organization->forceFill(['id' => $organizationId, 'name' => 'Browser fixture'])->save();
        }
        if ($organization->name !== 'Browser fixture') {
            throw new \LogicException('Fixture organization collision.');
        }
        $user = User::where('email', 'browser-auth@example.test')->first();
        if ($user && $user->name !== 'Browser Fixture') {
            throw new \LogicException('Fixture user collision.');
        }
        $user ??= User::create(['name' => 'Browser Fixture', 'email' => 'browser-auth@example.test', 'password' => 'test-only-password-123']);
        $user->forceFill(['password' => 'test-only-password-123', 'email_verified_at' => now(), 'mfa_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'mfa_confirmed_at' => now(), 'mfa_last_counter' => null])->save();
        app(TenantContextRunner::class)->run(new TenantContext($organizationId, actorId: $user->id, source: 'job'), function () use ($user): void {
            if (! Brand::withoutGlobalScopes()->where('organization_id', app(Cabinet::class)->organizationId())->exists()) {
                Brand::create(['name' => 'Browser fixture']);
            }
            if (! Membership::withoutGlobalScopes()->where('organization_id', app(Cabinet::class)->organizationId())->where('user_id', $user->id)->exists()) {
                Membership::create(['user_id' => $user->id, 'role' => 'owner']);
            }
        });
        $organization->forceFill(['owner_user_id' => $user->id])->save();
        $this->info('Synthetic authentication fixture ready.');

        return self::SUCCESS;
    }
}
