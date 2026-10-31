<?php

namespace Tests\Feature\Shell\Auth;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Notifications\ConfigureNotification;
use App\Shell\Notifications\Http\NotificationSettingsController;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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

    public function test_notification_change_rechecks_session_after_request_was_authenticated(): void
    {
        $user = $this->actor();
        $this->login($user);
        $organization = Organization::query()->firstOrFail();
        $membership = Membership::withoutGlobalScopes()->where('organization_id', $organization->id)->where('user_id', $user->id)->firstOrFail();
        $brand = app(TenantContextRunner::class)->run(
            new TenantContext($organization->id, actorId: $user->id, source: 'job'),
            fn () => Brand::create(['name' => 'Session test brand', 'theme' => 'neutral'])
        );
        $context = new TenantContext($organization->id, $brand->id, $user->id, $membership->id, 'session');
        $request = Request::create('/notifications/settings', 'PATCH', [
            'event_key' => 'seller.conversation.handed_off', 'channel' => 'cabinet', 'enabled' => false,
        ]);
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $user);
        // Simulates logout after middleware authenticated this request.
        DB::table('auth_session_registry')->where('user_id', $user->id)->update(['revoked_at' => now()]);
        try {
            app(NotificationSettingsController::class)->update($request, app(ConfigureNotification::class), $context);
            self::fail('Revoked session must not update settings.');
        } catch (AuthenticationException) {
            self::assertSame(0, DB::table('notification_settings')->where('user_id', $user->id)->count());
            self::assertSame(0, DB::table('audit_logs')->where('organization_id', $organization->id)->count());
        }
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
