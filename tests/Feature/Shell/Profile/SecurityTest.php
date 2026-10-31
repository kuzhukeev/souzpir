<?php

namespace Tests\Feature\Shell\Profile;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\Totp;
use App\Shell\Profile\Security\SecuritySettings;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class SecurityTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'current-safe-password';

    private const REPLACEMENT = 'replacement-safe-password';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
    }

    private function actor(string $role = 'owner'): array
    {
        $org = Organization::create(['name' => 'Security']);
        config(['features.E0-02.defaults.organization_id' => $org->id]);
        $user = User::create(['name' => 'Пользователь', 'email' => 'security@example.test', 'password' => self::PASSWORD]);
        $user->forceFill(['email_verified_at' => now(), 'remember_token' => Str::random(60)])->save();
        [$brand, $member] = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $user->id), function () use ($user, $role): array {
            $brand = Brand::create(['name' => 'Brand']);
            $member = Membership::create(['user_id' => $user->id, 'role' => $role, 'brand_ids' => [$brand->id]]);

            return [$brand, $member];
        });
        if ($role === 'owner') {
            $org->forceFill(['owner_user_id' => $user->id])->save();
        }

        return [$org, $user->refresh(), $brand, $member];
    }

    private function login(User $user): string
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.15', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0 Safari/537.36'])
            ->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect();
        $id = session()->getId();
        $this->withCookie(config('session.cookie'), $id);
        $this->get('/profile/security')->assertOk();

        return $id;
    }

    private function lease(User $user, array $override = []): array
    {
        $raw = Str::random(40);
        $hash = hash_hmac('sha256', 'auth-session:'.$raw, (string) config('app.key'));
        DB::table('auth_session_registry')->insert([
            'session_hash' => $hash, 'user_id' => $user->id, 'auth_version' => $user->auth_version,
            'remember_fingerprint' => null, 'expires_at' => now()->addHour(), 'revoked_at' => null,
            'created_at' => now(), 'updated_at' => now(), ...$override,
        ]);

        return [$raw, $hash, app(SecuritySettings::class)->publicId($hash)];
    }

    private function leaseRequest(User $user, string $raw): Request
    {
        $session = new Store('security-test', new ArraySessionHandler(120), $raw);
        $session->start();
        $session->put('auth.version', $user->auth_version);
        $request = Request::create('/profile/security', 'GET');
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function active(User $user, string $raw): bool
    {
        try {
            app(AuthSessionRegistry::class)->withinActive($this->leaseRequest($user->fresh(), $raw), static fn () => null);

            return true;
        } catch (AuthenticationException) {
            return false;
        }
    }

    private function context(array $actor): TenantContext
    {
        [$org, $user, $brand, $member] = $actor;

        return new TenantContext($org->id, $brand->id, $user->id, $member->id);
    }

    public function test_security_lists_only_owned_live_devices_without_session_credentials(): void
    {
        [, $user] = $this->actor();
        $current = $this->login($user);
        [, $otherHash, $otherId] = $this->lease($user);
        $this->lease($user, ['expires_at' => now()->subSecond()]);
        $this->lease($user, ['revoked_at' => now()]);
        $this->lease($user, ['auth_version' => $user->auth_version + 1]);
        $foreign = User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => self::PASSWORD]);
        [, $foreignHash] = $this->lease($foreign->refresh());
        $response = $this->get('/profile/security')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        self::assertTrue($response->viewData('page')['encryptHistory']);
        $props = $response->viewData('page')['props'];
        $sessions = $props['security']['sessions'];
        self::assertCount(2, $sessions);
        self::assertTrue($sessions[0]['is_current']);
        self::assertSame('Chrome · Windows', $sessions[0]['device']);
        self::assertSame('203.0.113.15', $sessions[0]['ip_address']);
        self::assertSame($otherId, $sessions[1]['id']);
        self::assertSame('Браузер не определён', $sessions[1]['device']);
        self::assertNull($sessions[1]['ip_address']);
        $serialized = json_encode($props, JSON_THROW_ON_ERROR);
        foreach ([$current, $otherHash, $foreignHash, $user->password, $user->remember_token] as $credential) {
            self::assertStringNotContainsString($credential, $serialized);
        }
    }

    public function test_session_metadata_follows_rotation_and_is_bounded(): void
    {
        [, $user] = $this->actor();
        $old = $this->login($user);
        $oldHash = hash_hmac('sha256', 'auth-session:'.$old, (string) config('app.key'));
        $this->assertDatabaseHas('profile_session_details', ['session_hash' => $oldHash, 'user_id' => $user->id]);
        app(AuthSessionRegistry::class)->rotate(request());
        $new = session()->getId();
        self::assertNotSame($old, $new);
        $this->assertDatabaseMissing('profile_session_details', ['session_hash' => $oldHash]);
        $newHash = hash_hmac('sha256', 'auth-session:'.$new, (string) config('app.key'));
        $this->assertDatabaseHas('profile_session_details', ['session_hash' => $newHash, 'ip_address' => '203.0.113.15']);
        $this->withCookie(config('session.cookie'), $new)->get('/profile/security')->assertOk();
    }

    public function test_password_change_keeps_current_device_revokes_others_and_clears_recovery_tokens(): void
    {
        [, $user] = $this->actor();
        $current = $this->login($user);
        [$other] = $this->lease($user);
        $remember = $user->remember_token;
        DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => Hash::make('fake-reset-token'), 'auth_version' => $user->auth_version, 'created_at' => now()]);
        $this->put('/profile/security/password', [
            'current_password' => self::PASSWORD, 'password' => self::REPLACEMENT, 'password_confirmation' => self::REPLACEMENT,
        ])->assertRedirect(route('profile.security'));
        self::assertTrue(Hash::check(self::REPLACEMENT, $user->fresh()->password));
        self::assertSame($user->auth_version + 1, $user->fresh()->auth_version);
        self::assertNotSame($remember, $user->fresh()->remember_token);
        self::assertTrue($this->active($user, $current));
        self::assertFalse($this->active($user, $other));
        $this->get('/profile/security')->assertOk();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.password.changed', 'actor_id' => $user->id]);
        $audit = json_encode(DB::table('audit_logs')->get(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::PASSWORD, $audit);
        self::assertStringNotContainsString(self::REPLACEMENT, $audit);
    }

    public function test_password_validation_never_flashes_credentials_or_mutates(): void
    {
        [, $user] = $this->actor();
        $this->login($user);
        $this->from('/profile/security')->put('/profile/security/password', [
            'current_password' => 'incorrect-password', 'password' => self::REPLACEMENT, 'password_confirmation' => self::REPLACEMENT,
        ])->assertSessionHasErrors('current_password');
        foreach (['current_password', 'password', 'password_confirmation'] as $key) {
            self::assertNull(session()->getOldInput($key));
        }
        $this->put('/profile/security/password', ['current_password' => self::PASSWORD, 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');
        $this->put('/profile/security/password', ['current_password' => self::PASSWORD, 'password' => self::REPLACEMENT, 'password_confirmation' => 'different'])
            ->assertSessionHasErrors('password');
        self::assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
        self::assertSame(0, $user->fresh()->auth_version);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'account.password.changed']);
    }

    public function test_password_change_rechecks_revocation_and_fresh_password_after_middleware(): void
    {
        $actor = $this->actor();
        [, $user] = $actor;
        $raw = $this->login($user);
        $request = $this->leaseRequest($user, $raw);
        DB::table('users')->where('id', $user->id)->update(['password' => Hash::make(self::REPLACEMENT)]);
        try {
            app(TenantContextRunner::class)->run($this->context($actor), fn () => app(SecuritySettings::class)->password($request, ['current_password' => self::PASSWORD, 'password' => 'another-safe-password']));
            self::fail('Stale password accepted.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('current_password', $error->errors());
        }
        DB::table('auth_session_registry')->where('user_id', $user->id)->update(['revoked_at' => now()]);
        $this->expectException(AuthenticationException::class);
        app(TenantContextRunner::class)->run($this->context($actor), fn () => app(SecuritySettings::class)->password($request, ['current_password' => self::REPLACEMENT, 'password' => 'another-safe-password']));
    }

    public function test_audit_failure_rolls_back_password_version_and_current_session(): void
    {
        $actor = $this->actor();
        [, $user] = $actor;
        $raw = $this->login($user);
        $request = $this->leaseRequest($user, $raw);
        $this->mock(AuditWriter::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('sensitive original exception'));
        try {
            app(TenantContextRunner::class)->run($this->context($actor), fn () => app(SecuritySettings::class)->password($request, ['current_password' => self::PASSWORD, 'password' => self::REPLACEMENT]));
            self::fail('Audit failure did not fail action.');
        } catch (\RuntimeException $error) {
            self::assertSame('Password change processing failed.', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
        self::assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
        self::assertSame(0, $user->fresh()->auth_version);
        self::assertSame(0, $request->session()->get('auth.version'));
        self::assertTrue(Hash::check(self::PASSWORD, Auth::user()->password));
        self::assertTrue($this->active($user, $raw));
    }

    public function test_selective_revocation_preserves_other_devices_and_blocks_recallers(): void
    {
        [, $user] = $this->actor();
        $current = $this->login($user);
        $fingerprint = hash_hmac('sha256', 'auth-remember:'.$user->remember_token, (string) config('app.key'));
        [$target, $targetHash, $id] = $this->lease($user, ['remember_fingerprint' => $fingerprint]);
        [$survivor, $survivorHash] = $this->lease($user, ['remember_fingerprint' => $fingerprint]);
        $remember = $user->remember_token;
        $this->delete('/profile/security/sessions/'.$id)->assertRedirect(route('profile.security'));
        self::assertFalse($this->active($user, $target));
        self::assertTrue($this->active($user, $survivor));
        self::assertTrue($this->active($user, $current));
        self::assertNotSame($remember, $user->fresh()->remember_token);
        self::assertSame(0, $user->fresh()->auth_version);
        self::assertNotNull(DB::table('auth_session_registry')->where('session_hash', $targetHash)->value('revoked_at'));
        $this->assertDatabaseHas('auth_session_registry', ['session_hash' => $survivorHash, 'remember_fingerprint' => null, 'revoked_at' => null]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.session.revoked', 'actor_id' => $user->id]);
        $this->delete('/profile/security/sessions/'.$id)->assertNotFound();
        $this->get('/profile/security')->assertOk();
    }

    public function test_foreign_unknown_and_current_sessions_cannot_be_revoked(): void
    {
        [, $user] = $this->actor();
        $raw = $this->login($user);
        $other = User::create(['name' => 'Other', 'email' => 'foreign@example.test', 'password' => self::PASSWORD]);
        [, $foreignHash, $foreignId] = $this->lease($other->refresh());
        $this->delete('/profile/security/sessions/'.$foreignId)->assertNotFound();
        $this->delete('/profile/security/sessions/'.str_repeat('a', 64))->assertNotFound();
        $this->delete('/profile/security/sessions/not-an-id')->assertNotFound();
        $currentHash = hash_hmac('sha256', 'auth-session:'.$raw, (string) config('app.key'));
        $this->delete('/profile/security/sessions/'.app(SecuritySettings::class)->publicId($currentHash))->assertSessionHasErrors('sessions');
        $this->assertDatabaseHas('auth_session_registry', ['session_hash' => $foreignHash, 'revoked_at' => null]);
        self::assertTrue($this->active($user, $raw));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'account.session.revoked']);
    }

    public function test_revoke_all_excludes_current_and_invalidates_outstanding_version(): void
    {
        [, $user] = $this->actor();
        $current = $this->login($user);
        [$first] = $this->lease($user);
        [$second] = $this->lease($user);
        $this->delete('/profile/security/sessions')->assertRedirect(route('profile.security'));
        self::assertTrue($this->active($user, $current));
        self::assertFalse($this->active($user, $first));
        self::assertFalse($this->active($user, $second));
        self::assertSame(1, $user->fresh()->auth_version);
        $this->actingAs($user->fresh())->get('/profile/security')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.sessions.revoked', 'actor_id' => $user->id]);
    }

    public function test_audit_failure_rolls_back_revocation_and_remember_token(): void
    {
        $actor = $this->actor();
        [, $user] = $actor;
        $current = $this->login($user);
        [$target, , $id] = $this->lease($user);
        $request = $this->leaseRequest($user, $current);
        $this->mock(AuditWriter::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('audit unavailable'));
        try {
            app(TenantContextRunner::class)->run($this->context($actor), fn () => app(SecuritySettings::class)->revoke($request, $id));
            self::fail('Audit failure did not rollback.');
        } catch (\RuntimeException $error) {
            self::assertSame('audit unavailable', $error->getMessage());
        }
        self::assertTrue($this->active($user, $target));
        self::assertSame($user->remember_token, $user->fresh()->remember_token);
    }

    public function test_viewer_can_manage_own_security(): void
    {
        [, $user] = $this->actor('viewer');
        $this->login($user);
        [, , $id] = $this->lease($user);
        $this->get('/profile/security')->assertOk();
        $this->delete('/profile/security/sessions/'.$id)->assertRedirect(route('profile.security'));
        $this->put('/profile/security/password', ['current_password' => self::PASSWORD, 'password' => self::REPLACEMENT, 'password_confirmation' => self::REPLACEMENT])
            ->assertRedirect(route('profile.security'));
    }

    public function test_profile_mfa_setup_cancel_expiry_confirm_replay_and_disable(): void
    {
        [, $user] = $this->actor();
        $this->login($user);
        $this->post('/profile/security/mfa/setup', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->post('/profile/security/mfa/setup', ['password' => self::PASSWORD])->assertRedirect(route('profile.security'));
        $secret = session('auth.mfa_setup.secret');
        $props = $this->get('/profile/security')->assertOk()->viewData('page')['props'];
        self::assertSame($secret, $props['security']['setup']['secret']);
        self::assertStringStartsWith('otpauth://totp/', $props['security']['setup']['otpauth_url']);
        self::assertNull($user->fresh()->mfa_confirmed_at);
        $this->delete('/profile/security/mfa/setup')->assertRedirect(route('profile.security'));
        self::assertNull(session('auth.mfa_setup'));
        self::assertTrue($this->get('/profile/security')->assertOk()->viewData('page')['clearHistory']);
        $this->post('/profile/security/mfa/setup', ['password' => self::PASSWORD])->assertRedirect(route('profile.security'));
        $this->travel(10)->minutes();
        $props = $this->get('/profile/security')->assertOk()->viewData('page')['props'];
        self::assertNull($props['security']['setup']);
        $this->post('/profile/security/mfa/setup', ['password' => self::PASSWORD])->assertRedirect(route('profile.security'));
        $secret = session('auth.mfa_setup.secret');
        $code = app(Totp::class)->at($secret, intdiv(now()->timestamp, 30));
        $this->post('/profile/security/mfa/confirm', ['code' => $code])->assertRedirect(route('profile.security'));
        self::assertNotNull($user->fresh()->mfa_confirmed_at);
        self::assertNull(session('auth.mfa_setup'));
        self::assertTrue($this->get('/profile/security')->assertOk()->viewData('page')['clearHistory']);
        $this->post('/profile/security/mfa/disable', ['password' => self::PASSWORD, 'code' => $code])->assertSessionHasErrors('code');
        $this->travel(31)->seconds();
        $code = app(Totp::class)->at($secret, intdiv(now()->timestamp, 30));
        $this->post('/profile/security/mfa/disable', ['password' => self::PASSWORD, 'code' => $code])->assertRedirect(route('profile.security'));
        self::assertNull($user->fresh()->mfa_confirmed_at);
        $this->get('/profile/security')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.mfa.enabled', 'actor_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.mfa.disabled', 'actor_id' => $user->id]);
        self::assertStringNotContainsString($secret, json_encode(DB::table('audit_logs')->get(), JSON_THROW_ON_ERROR));
    }

    public function test_mfa_setup_from_another_user_or_version_is_not_exposed(): void
    {
        [, $user] = $this->actor();
        $this->login($user);
        session()->put('auth.mfa_setup', ['user_id' => (string) Str::ulid(), 'version' => 0, 'secret' => str_repeat('A', 32), 'expires' => now()->addMinutes(10)->timestamp]);
        self::assertNull($this->get('/profile/security')->assertOk()->viewData('page')['props']['security']['setup']);
        session()->put('auth.mfa_setup', ['user_id' => $user->id, 'version' => 1, 'secret' => str_repeat('A', 32), 'expires' => now()->addMinutes(10)->timestamp]);
        self::assertNull($this->get('/profile/security')->assertOk()->viewData('page')['props']['security']['setup']);
    }

    public function test_session_metadata_rejects_invalid_ip_and_bounds_untrusted_user_agent(): void
    {
        [, $user] = $this->actor();
        $this->login($user);
        $raw = Str::random(40);
        $store = new Store('metadata-test', new ArraySessionHandler(120), $raw);
        $store->start();
        $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => 'invalid-ip', 'HTTP_USER_AGENT' => str_repeat('X', 600)."\r\nInjected"]);
        $request->setLaravelSession($store);
        $request->setUserResolver(fn () => $user);
        app(AuthSessionRegistry::class)->issue($request, $user, $user->auth_version);
        $hash = hash_hmac('sha256', 'auth-session:'.$raw, (string) config('app.key'));
        $row = DB::table('profile_session_details')->where('session_hash', $hash)->first();
        self::assertNull($row->ip_address);
        self::assertSame(str_repeat('X', 500), $row->user_agent);
    }

    public function test_personal_timezone_changes_security_runtime_and_null_falls_back_to_brand(): void
    {
        [, $user, $brand] = $this->actor();
        $this->login($user);
        $this->put('/profile', ['name' => 'Пользователь', 'phone' => null, 'language' => 'ru', 'timezone' => 'Asia/Tokyo'])
            ->assertRedirect(route('profile.show'));
        self::assertSame('Asia/Tokyo', $this->get('/profile/security')->assertOk()->viewData('page')['props']['security']['timezone']);
        $this->put('/profile', ['name' => 'Пользователь', 'phone' => null, 'language' => 'ru', 'timezone' => null])
            ->assertRedirect(route('profile.show'));
        self::assertSame(DB::table('brands')->where('id', $brand->id)->value('timezone'), $this->get('/profile/security')->assertOk()->viewData('page')['props']['security']['timezone']);
    }

    public function test_guests_and_revoked_sessions_cannot_access_personal_security(): void
    {
        $this->get('/profile/security')->assertRedirect(route('login'));
        [, $user] = $this->actor();
        $this->login($user);
        DB::table('auth_session_registry')->where('user_id', $user->id)->update(['revoked_at' => now()]);
        $this->put('/profile/security/password', ['current_password' => self::PASSWORD, 'password' => self::REPLACEMENT, 'password_confirmation' => self::REPLACEMENT])->assertRedirect(route('login'));
        self::assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }
}
