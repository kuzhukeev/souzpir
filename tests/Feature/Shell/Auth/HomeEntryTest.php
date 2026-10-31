<?php

namespace Tests\Feature\Shell\Auth;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class HomeEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_directed_to_real_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_authenticated_user_is_directed_to_cabinet_entry(): void
    {
        $organization = Organization::create(['name' => 'Cabinet']);
        config(['features.E0-02.defaults.organization_id' => $organization->id]);
        $user = User::create(['name' => 'Owner', 'email' => 'home@example.test', 'password' => 'test-password-strong']);
        $user->forceFill(['email_verified_at' => now()])->save();
        app(TenantContextRunner::class)->run(new TenantContext($organization->id, actorId: $user->id), fn () => Membership::create(['user_id' => $user->id, 'role' => 'owner']));
        $this->post('/login', ['email' => $user->email, 'password' => 'test-password-strong'])->assertRedirect();
        $this->withCookie(config('session.cookie'), session()->getId())->get('/')->assertRedirect(route('auth.ready'));
    }
}
