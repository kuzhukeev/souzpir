<?php

namespace Tests\Feature\Launch\L1_07;

use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\PasswordDelivery\PasswordTransport;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class OwnerInvitationAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Route::middleware(['web', 'auth', 'verified', 'tenant'])
            ->get('/l1-07-test/whoami', fn () => response()->json([
                'organization_id' => app(TenantContext::class)->organizationId,
                'brand_id' => app(TenantContext::class)->brandId,
            ]))->name('l1-07-test.whoami');
    }

    private function openAndExchange(string $token): void
    {
        $page = $this->get('/invitations/open')->assertOk();
        self::assertSame(1, preg_match('/epoch:"([a-f0-9]{64})"/', $page->getContent(), $match));
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->withCredentials()->postJson('/invitations/exchange', ['token' => $token, 'epoch' => $match[1], 'sequence' => 1])->assertOk();
    }

    public function test_owner_sets_password_via_the_link_and_lands_in_the_new_organization(): void
    {
        config(['features.E0-02.defaults.organization_id' => Organization::create(['name' => 'Reserve cabinet'])->id]);

        Artisan::call('org:create', ['name' => 'Fresh Client', 'owner_email' => 'owner@fresh-client.test']);
        preg_match('~/invitations/open#([A-Za-z0-9]{64})~', Artisan::output(), $match);
        $token = $match[1];
        $organization = Organization::where('name', 'Fresh Client')->firstOrFail();

        $this->openAndExchange($token);
        $this->get('/invitations/show')->assertOk()->assertInertia(fn ($page) => $page->where('email', 'owner@fresh-client.test')->where('role', 'admin'));
        $this->post('/invitations/register', [
            'name' => 'Fresh Owner', 'password' => 'a-very-secure-password', 'password_confirmation' => 'a-very-secure-password', 'terms' => true,
        ])->assertRedirect(route('verification.notice'));
        $owner = User::where('email', 'owner@fresh-client.test')->firstOrFail();
        $this->assertDatabaseMissing('memberships', ['user_id' => $owner->id]);
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->post('/invitations/accept')->assertRedirect(route('verification.notice'));
        $owner->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($owner->fresh());
        $this->post('/invitations/accept')->assertRedirect(route('auth.ready'));

        $this->assertDatabaseHas('memberships', ['organization_id' => $organization->id, 'user_id' => $owner->id, 'role' => 'owner', 'status' => 'active']);
        $this->assertSame($owner->id, $organization->fresh()->owner_user_id);

        $this->withCookie(config('session.cookie'), session()->getId());
        $this->get('/l1-07-test/whoami')->assertOk()->assertJson(['organization_id' => $organization->id]);
    }

    public function test_login_and_password_reset_work_for_a_non_reserve_organization_owner(): void
    {
        config(['features.E0-02.defaults.organization_id' => Organization::create(['name' => 'Reserve cabinet'])->id]);
        config(['features.E1-01F.defaults.enabled' => true]);
        app()->instance(PasswordTransport::class, new class implements PasswordTransport
        {
            public function send(#[\SensitiveParameter] User $user, #[\SensitiveParameter] string $token): bool
            {
                return true;
            }
        });

        Artisan::call('org:create', ['name' => 'Second Client', 'owner_email' => 'owner@second-client.test']);
        preg_match('~/invitations/open#([A-Za-z0-9]{64})~', Artisan::output(), $match);
        $this->openAndExchange($match[1]);
        $this->post('/invitations/register', [
            'name' => 'Second Owner', 'password' => 'another-secure-password', 'password_confirmation' => 'another-secure-password', 'terms' => true,
        ])->assertRedirect(route('verification.notice'));
        $owner = User::where('email', 'owner@second-client.test')->firstOrFail();
        $owner->forceFill(['email_verified_at' => now()])->save();
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->actingAs($owner->fresh());
        $this->post('/invitations/accept')->assertRedirect(route('auth.ready'));

        $this->post('/logout');
        $this->post('/login', ['email' => 'owner@second-client.test', 'password' => 'another-secure-password'])->assertRedirect(route('auth.ready'));
        $this->assertAuthenticatedAs($owner->fresh());

        $this->post('/logout');
        $this->post('/forgot-password', ['email' => 'owner@second-client.test'])->assertRedirect(route('password.request'));
        $this->assertDatabaseHas('password_delivery_intents', ['user_id' => $owner->id, 'status' => 'pending']);
    }
}
