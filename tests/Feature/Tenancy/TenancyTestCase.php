<?php

namespace Tests\Feature\Tenancy;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Tests\Feature\Tenancy\Fixtures\TenantRecord;
use Tests\TestCase;

abstract class TenancyTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withoutVite();
        Schema::create('tenancy_test_records', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->ulid('brand_id');
            $t->string('name');
            $t->timestamps();
            $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
        });
        Route::middleware(['web', 'auth', 'verified', 'tenant'])->group(function (): void {
            Route::get('/tenancy-test/page', fn () => Inertia::render('Tenancy/Test', ['can' => []]));
            Route::get('/tenancy-test/records/{id}', fn (string $id) => TenantRecord::findOrFail($id));
            Route::get('/tenancy-test/implicit/{record}', fn (TenantRecord $record) => $record);
            Route::get('/tenancy-test/brands/{id}', fn (string $id) => Brand::findOrFail($id));
            Route::post('/tenancy-test/records', fn () => TenantRecord::create(['name' => 'Created']));
        });
    }

    protected function user(bool $verified = true): User
    {
        $user = User::create(['name' => 'Test actor', 'email' => Str::lower(Str::random(12)).'@example.test', 'password' => 'test-only-password']);
        $user->forceFill(['email_verified_at' => $verified ? now() : null])->save();

        return $user;
    }

    protected function cabinet(bool $configured = true): array
    {
        $owner = $this->user();
        $organization = Organization::create(['name' => 'Fixture cabinet', 'owner_user_id' => $owner->id]);
        [$membership,$first,$second] = $this->within($organization, null, function () use ($owner): array {
            $membership = Membership::create(['user_id' => $owner->id, 'role' => 'owner']);

            return [$membership, Brand::create(['name' => 'A', 'theme' => 'confectionery']), Brand::create(['name' => 'B', 'theme' => 'pyro'])];
        });
        if ($configured) {
            config(['features.E0-02.defaults.organization_id' => $organization->id]);
        }

        return [$owner, $organization, $first, $second, $membership];
    }

    protected function within(Organization $organization, ?Brand $brand, \Closure $callback): mixed
    {
        return app(TenantContextRunner::class)->run(new TenantContext($organization->id, $brand?->id, source: 'job'), $callback);
    }

    protected function member(Organization $organization, string $role, array $brandIds = [], array $settings = []): array
    {
        $user = $this->user();
        $membership = $this->within($organization, null, fn () => Membership::create(['user_id' => $user->id, 'role' => $role, 'brand_ids' => $brandIds, 'settings' => $settings]));

        return [$user, $membership];
    }
}
