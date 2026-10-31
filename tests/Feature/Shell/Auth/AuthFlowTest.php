<?php

namespace Tests\Feature\Shell\Auth;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\Http\SessionController;
use App\Shell\Auth\PasswordDelivery\PasswordTransport;
use App\Shell\Auth\Totp;
use App\Shell\Team\Models\Invitation;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
    }

    private function fixture(bool $withOwner = true): array
    {
        $org = Organization::create(['name' => 'Cabinet']);
        config(['features.E0-02.defaults.organization_id' => $org->id]);
        if (! $withOwner) {
            return [$org];
        }
        $user = User::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'secure-password-example']);
        $user->forceFill(['email_verified_at' => now()])->save();
        [$brand, $member] = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $user->id), fn () => [Brand::create(['name' => 'Brand']), Membership::create(['user_id' => $user->id, 'role' => 'owner'])]);
        $org->forceFill(['owner_user_id' => $user->id])->save();

        return [$org, $user->refresh(), $brand, $member];
    }

    public function test_login_completion_rejects_revoked_authorization_version(): void
    {
        [, $user] = $this->fixture();
        $expected = $user->auth_version;
        User::whereKey($user->id)->increment('auth_version');
        $request = Request::create('/login', 'POST');
        $request->setLaravelSession(app('session.store'));
        try {
            app(SessionController::class)->complete($request, $user, false, $expected);
            self::fail('Revoked login accepted.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('email', $e->errors());
            $this->assertGuest();
        }
    }

    public function test_failed_login_completion_clears_remember_cookie_and_rolls_back_token(): void
    {
        [, $user] = $this->fixture();
        $request = Request::create('/login', 'POST');
        $request->setLaravelSession(app('session.store'));
        Event::listen(Login::class, fn () => throw new \RuntimeException('login listener failure'));

        try {
            app(SessionController::class)->complete($request, $user, true, $user->auth_version);
            self::fail('Expected login to fail.');
        } catch (\RuntimeException $e) {
            self::assertSame('login listener failure', $e->getMessage());
            self::assertFalse(Auth::check());
            self::assertFalse(Cookie::hasQueued(Auth::guard()->getRecallerName()));
            self::assertEmpty($user->fresh()->getRememberToken());
            self::assertNull($request->session()->get('auth.version'));
        }
    }

    public function test_initial_owner_is_single_organization_and_requires_secret(): void
    {
        [$org] = $this->fixture(false);
        $secret = str_repeat('x', 40);
        config(['features.E1-01.defaults.bootstrap_token_hash' => hash('sha256', $secret)]);
        $data = ['name' => 'Owner', 'email' => 'OWNER@example.test', 'password' => 'secure-password-example', 'password_confirmation' => 'secure-password-example', 'terms' => true, 'ai_consent' => true, 'bootstrap_code' => 'wrong-wrong-wrong-wrong-wrong-wrong'];
        $this->post('/register', $data)->assertSessionHasErrors('bootstrap_code');
        $this->assertNull(session()->getOldInput('bootstrap_code'));
        $this->assertDatabaseCount('users', 0);
        $this->post('/register', [...$data, 'bootstrap_code' => $secret, 'ai_consent' => false])->assertSessionHasErrors('ai_consent');
        $this->assertDatabaseCount('users', 0);
        $this->assertNull(session()->getOldInput('bootstrap_code'));
        $this->post('/register', [...$data, 'bootstrap_code' => $secret])->assertRedirect(route('verification.notice'));
        $user = User::where('email', 'owner@example.test')->firstOrFail();
        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseHas('memberships', ['organization_id' => $org->id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.owner_registered', 'actor_id' => $user->id]);
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->post('/logout')->assertRedirect(route('login'));
        $this->post('/register', [...$data, 'email' => 'other@example.test', 'bootstrap_code' => $secret])->assertSessionHasErrors('bootstrap_code');
        $this->assertNull(session()->getOldInput('bootstrap_code'));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_login_mfa_challenge_replay_and_no_preauth_access(): void
    {
        [$org, $user] = $this->fixture();
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $user->forceFill(['mfa_secret' => $secret, 'mfa_confirmed_at' => now(), 'mfa_last_counter' => null])->save();
        $this->freezeTime();
        $code = app(Totp::class)->at($secret, intdiv(now()->timestamp, 30));
        $this->post('/login', ['email' => $user->email, 'password' => 'secure-password-example'])->assertRedirect(route('mfa.challenge'));
        $this->assertGuest();
        $this->get('/auth/ready')->assertRedirect(route('login'));
        $this->post('/mfa/challenge', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->post('/mfa/challenge', ['code' => $code])->assertRedirect(route('auth.ready'));
        $this->assertAuthenticatedAs($user);
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'secure-password-example']);
        $this->post('/mfa/challenge', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_remember_cookie_is_issued_only_after_mfa_and_session_is_registered(): void
    {
        [, $user] = $this->fixture();
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $user->forceFill(['mfa_secret' => $secret, 'mfa_confirmed_at' => now(), 'mfa_last_counter' => null])->save();
        $recallerName = auth()->guard()->getRecallerName();
        $pending = $this->post('/login', ['email' => $user->email, 'password' => 'secure-password-example', 'remember' => true]);
        $pending->assertRedirect(route('mfa.challenge'));
        self::assertFalse(collect($pending->headers->getCookies())->contains(fn ($cookie) => $cookie->getName() === $recallerName));
        $this->withCookie(config('session.cookie'), session()->getId());
        $code = app(Totp::class)->at($secret, intdiv(now()->timestamp, 30));
        $verified = $this->post('/mfa/challenge', ['code' => $code]);
        $verified->assertRedirect(route('auth.ready'));
        self::assertTrue(collect($verified->headers->getCookies())->contains(fn ($cookie) => $cookie->getName() === $recallerName));
        $this->assertDatabaseHas('auth_session_registry', ['user_id' => $user->id, 'revoked_at' => null]);
    }

    public function test_verification_link_get_is_safe_post_verifies_and_reset_is_one_use(): void
    {
        [$org, $user] = $this->fixture();
        $user->forceFill(['email_verified_at' => null])->save();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->post('/login', ['email' => $user->email, 'password' => 'secure-password-example'])->assertRedirect();
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->get($url)->assertOk();
        $this->assertNull($user->fresh()->email_verified_at);
        $this->post($url)->assertRedirect(route('auth.ready'));
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->post('/logout');
        config(['features.E1-01F.defaults.enabled' => true, 'queue.default' => 'sync']);
        app()->instance(PasswordTransport::class, new class implements PasswordTransport
        {
            public function send(#[\SensitiveParameter] User $user, #[\SensitiveParameter] string $token): bool
            {
                return true;
            }
        });
        $this->post('/forgot-password', ['email' => 'unknown@example.test'])->assertSessionHas('status');
        $this->assertDatabaseCount('password_delivery_intents', 0);
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');
        $this->assertDatabaseHas('password_delivery_intents', ['user_id' => $user->id, 'status' => 'pending']);
        Notification::assertNotSentTo($user, ResetPassword::class);
        // RefreshDatabase holds a transaction: the actual worker is covered by PasswordDeliveryTest.
        $token = Password::createToken($user);
        DB::table('password_reset_tokens')->where('email', $user->email)->update(['auth_version' => $user->auth_version]);
        $data = ['email' => $user->email, 'token' => $token, 'password' => 'a-different-strong-password', 'password_confirmation' => 'a-different-strong-password'];
        $this->post('/reset-password', $data)->assertRedirect(route('login'));
        $this->assertTrue(Hash::check($data['password'], $user->fresh()->password));
        $this->post('/reset-password', $data)->assertSessionHasErrors('email');
        $this->assertNull(session()->getOldInput('token'));
        $this->assertSame(1, $user->fresh()->auth_version);
    }

    public function test_mfa_enrolment_requires_password_and_rotates_other_sessions(): void
    {
        [$org, $user] = $this->fixture();
        $this->post('/login', ['email' => $user->email, 'password' => 'secure-password-example'])->assertRedirect();
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->post('/mfa/setup', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->post('/mfa/setup', ['password' => 'secure-password-example'])->assertRedirect(route('mfa.setup'));
        $secret = session('auth.mfa_setup.secret');
        $code = app(Totp::class)->at($secret, intdiv(now()->timestamp, 30));
        $this->post('/mfa/setup/confirm', ['code' => $code])->assertRedirect(route('auth.ready'));
        $this->assertNotNull($user->fresh()->mfa_confirmed_at);
        $this->assertNotSame($secret, DB::table('users')->where('id', $user->id)->value('mfa_secret'));
        $this->assertSame(1, $user->fresh()->auth_version);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $user->id, 'action' => 'account.mfa.enabled']);
    }

    public function test_invitation_registration_verification_and_acceptance_are_separate_and_single_use(): void
    {
        [$org, $owner, $brand] = $this->fixture();
        $token = str_repeat('a', 64);
        $invitation = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $owner->id), fn () => Invitation::create([
            'email' => 'member@example.test', 'role' => 'member', 'brand_ids' => [$brand->id],
            'token_hash' => hash('sha256', $token), 'invited_by' => $owner->id, 'expires_at' => now()->addDay(),
        ]));
        $this->get('/invitations/'.$token)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->post('/invitations/'.$token.'/register', ['name' => 'Member', 'password' => 'another-secure-password', 'password_confirmation' => 'another-secure-password', 'terms' => true])->assertRedirect(route('verification.notice'));
        $member = User::where('email', 'member@example.test')->firstOrFail();
        $this->assertDatabaseMissing('memberships', ['user_id' => $member->id]);
        $this->assertAuthenticatedAs($member);
        $this->assertDatabaseHas('auth_session_registry', ['user_id' => $member->id, 'revoked_at' => null]);
        $this->assertDatabaseHas('auth_session_registry', ['session_hash' => hash_hmac('sha256', 'auth-session:'.session()->getId(), (string) config('app.key'))]);
        self::assertSame($member->auth_version, session('auth.version'));
        $registryRow = DB::table('auth_session_registry')->where('user_id', $member->id)->first();
        self::assertTrue(now()->lessThan($registryRow->expires_at));
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->post('/invitations/'.$token.'/accept')->assertRedirect(route('verification.notice'));
        $member->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($member->fresh());
        $this->post('/invitations/'.$token.'/accept')->assertRedirect(route('auth.ready'));
        $this->assertDatabaseHas('memberships', ['organization_id' => $org->id, 'user_id' => $member->id, 'role' => 'member']);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $member->id, 'action' => 'account.invitation_accepted']);
        $this->get('/invitations/'.$token)->assertNotFound();
        $this->assertDatabaseCount('organizations', 1);
    }

    public function test_foreign_or_disabled_account_cannot_login_and_attempts_are_limited(): void
    {
        [$org] = $this->fixture();
        $stranger = User::create(['name' => 'Stranger', 'email' => 'stranger@example.test', 'password' => 'secure-password-example']);
        $this->post('/login', ['email' => $stranger->email, 'password' => 'secure-password-example'])->assertSessionHasErrors('email');
        $this->assertGuest();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'missing@example.test', 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => 'missing@example.test', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertStringContainsString('15 минут', session('errors')->first('email'));
    }

    public function test_pending_mfa_cannot_complete_after_account_version_changes(): void
    {
        [$org, $user] = $this->fixture();
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $user->forceFill(['mfa_secret' => $secret, 'mfa_confirmed_at' => now()])->save();
        $code = app(Totp::class)->at($secret, intdiv(now()->timestamp, 30));
        $this->post('/login', ['email' => $user->email, 'password' => 'secure-password-example'])->assertRedirect(route('mfa.challenge'));
        $user->increment('auth_version');
        $this->post('/mfa/challenge', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_auth_version_revokes_old_sessions_and_owner_registration_does_not_send_directly(): void
    {
        [$org] = $this->fixture(false);
        $secret = str_repeat('y', 40);
        config(['features.E1-01.defaults.bootstrap_token_hash' => hash('sha256', $secret), 'features.E1-01.defaults.verification_enabled' => true]);
        $this->post('/register', ['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'secure-password-example', 'password_confirmation' => 'secure-password-example', 'terms' => true, 'ai_consent' => true, 'bootstrap_code' => $secret])->assertRedirect(route('verification.notice'));
        $user = User::where('email', 'owner@example.test')->firstOrFail();
        Notification::assertNotSentTo($user, VerifyEmail::class);
        $user->forceFill(['email_verified_at' => now(), 'auth_version' => 2])->save();
        $this->actingAs($user->fresh())->withSession(['auth.version' => 1])->get('/auth/ready')->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
