<?php

namespace Tests\Feature\Shell\Auth;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AuthSessionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        $org = Organization::create(['name' => 'Session organization']);
        config(['features.E0-02.defaults.organization_id' => $org->id]);
        $user = User::create(['name' => 'Owner', 'email' => 'session@example.test', 'password' => 'session-password-strong']);
        $user->forceFill(['email_verified_at' => now()])->save();
        app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $user->id), fn () => Membership::create(['user_id' => $user->id, 'role' => 'owner']));
        $this->withoutVite();

        return $user->refresh();
    }

    private function login(User $user): string
    {
        $this->post('/login', ['email' => $user->email, 'password' => 'session-password-strong'])->assertRedirect();
        $id = session()->getId();
        $this->withCookie(config('session.cookie'), $id);

        return $id;
    }

    public function test_expired_registry_entry_denies_access_without_changing_password_version(): void
    {
        $user = $this->actor();
        $this->login($user);
        $this->get('/auth/ready')->assertOk();
        DB::table('auth_session_registry')->where('user_id', $user->id)->update(['expires_at' => now()->subSecond()->format('Y-m-d H:i:s.uP')]);
        $this->get('/auth/ready')->assertRedirect(route('login'));
        self::assertSame(0, $user->fresh()->auth_version);
    }

    public function test_explicit_regeneration_retires_old_id_and_keeps_new_one(): void
    {
        $user = $this->actor();
        $oldId = $this->login($user);
        app(AuthSessionRegistry::class)->rotate(request());
        $newId = session()->getId();
        self::assertNotSame($oldId, $newId);
        $this->withCookie(config('session.cookie'), $newId)->get('/auth/ready')->assertOk();
        $this->withCookie(config('session.cookie'), $oldId)->get('/auth/ready')->assertRedirect(route('login'));
    }

    public function test_unregistered_legacy_session_is_rejected(): void
    {
        $user = $this->actor();
        $this->actingAs($user)->withSession(['auth.version' => $user->auth_version]);
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->get('/auth/ready')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_password_version_change_revokes_registered_session(): void
    {
        $user = $this->actor();
        $this->login($user);
        $user->increment('auth_version');
        $this->get('/auth/ready')->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
