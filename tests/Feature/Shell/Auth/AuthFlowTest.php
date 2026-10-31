<?php

namespace Tests\Feature\Shell\Auth;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\Http\SessionController;
use App\Shell\Auth\InvitationExchangeGrants;
use App\Shell\Auth\InvitationSecretBoundary;
use App\Shell\Auth\PasswordDelivery\PasswordTransport;
use App\Shell\Auth\Totp;
use App\Shell\Team\Models\Invitation;
use App\Tenancy\Invitations\InvitationAcceptance;
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
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\MfaTestDatabase;
use Tests\TestCase;

final class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        MfaTestDatabase::assert();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
    }

    private function exchangeInvitation(string $token): TestResponse
    {
        $page = $this->get('/invitations/open')->assertOk();
        self::assertSame(1, preg_match('/epoch:"([a-f0-9]{64})"/', $page->getContent(), $match));

        $this->withCookie(config('session.cookie'), session()->getId());

        return $this->withCredentials()->postJson('/invitations/exchange', ['token' => $token, 'epoch' => $match[1], 'sequence' => 1]);
    }

    public function test_invitation_grant_is_one_use_and_old_documents_and_sequence_numbers_cannot_replace_it(): void
    {
        [$org, $owner] = $this->fixture();
        $tokens = [str_repeat('A', 64), str_repeat('B', 64)];
        $invites = [];
        foreach ($tokens as $index => $token) {
            $invites[] = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $owner->id), fn () => Invitation::create([
                'email' => 'user'.$index.'@example.test', 'role' => 'member', 'brand_ids' => [],
                'token_hash' => hash('sha256', $token), 'invited_by' => $owner->id, 'expires_at' => now()->addDay(),
            ]));
        }
        $first = $this->get('/invitations/open')->assertOk();
        preg_match('/epoch:"([a-f0-9]{64})"/', $first->getContent(), $match);
        $oldEpoch = $match[1];
        $this->withCookie(config('session.cookie'), session()->getId())->withCredentials();
        $second = $this->get('/invitations/open')->assertOk();
        preg_match('/epoch:"([a-f0-9]{64})"/', $second->getContent(), $match);
        $epoch = $match[1];
        $this->postJson('/invitations/exchange', ['token' => $tokens[1], 'epoch' => $epoch, 'sequence' => 2])->assertOk();
        $this->postJson('/invitations/exchange', ['token' => $tokens[0], 'epoch' => $oldEpoch, 'sequence' => 100])->assertNotFound();
        $this->postJson('/invitations/exchange', ['token' => $tokens[0], 'epoch' => $epoch, 'sequence' => 1])->assertNotFound();
        self::assertSame($invites[1]->id, app(InvitationExchangeGrants::class)->invitationId(request()));
        self::assertNull($invites[0]->fresh()->exchanged_at);
        self::assertNotNull($invites[1]->fresh()->exchanged_at);
        $other = User::create(['name' => 'Invited', 'email' => $invites[1]->email, 'password' => 'another-secure-password']);
        $other->forceFill(['email_verified_at' => now()])->save();
        try {
            app(InvitationAcceptance::class)->accept($other, $tokens[1]);
            self::fail('The direct API accepted an already exchanged bearer.');
        } catch (HttpException $error) {
            self::assertSame(404, $error->getStatusCode());
        }
        $this->postJson('/invitations/exchange', ['token' => $tokens[1], 'epoch' => $epoch, 'sequence' => 3])->assertNotFound();
        self::assertNull(app(InvitationExchangeGrants::class)->invitationId(request()));
    }

    public function test_exchange_cleanup_removes_only_expired_idle_sessions(): void
    {
        $old = now()->subMinutes((int) config('session.lifetime', 120) + 1);
        foreach ([['a', $old, null], ['b', $old, now()->addHour()], ['c', now(), null]] as [$character, $updated, $expires]) {
            DB::table('invitation_exchange_sessions')->insert([
                'session_hash' => str_repeat($character, 64), 'document_epoch' => str_repeat('d', 64),
                'sequence' => 0, 'grant_expires_at' => $expires, 'created_at' => $old, 'updated_at' => $updated,
            ]);
        }
        $this->artisan('auth:prune-invitation-exchanges')->assertSuccessful();
        self::assertFalse(DB::table('invitation_exchange_sessions')->where('session_hash', str_repeat('a', 64))->exists());
        self::assertTrue(DB::table('invitation_exchange_sessions')->where('session_hash', str_repeat('b', 64))->exists());
        self::assertTrue(DB::table('invitation_exchange_sessions')->where('session_hash', str_repeat('c', 64))->exists());
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
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->post('/logout')->assertRedirect(route('login'));
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->post('/login', ['email' => $user->email, 'password' => 'secure-password-example'])->assertRedirect(route('mfa.challenge'));
        $this->withCookie(config('session.cookie'), session()->getId());
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
        // Symfony's synthetic request otherwise has no server address.
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        [$org, $user] = $this->fixture();
        $this->post('/login', ['email' => $user->email, 'password' => 'secure-password-example'])->assertRedirect();
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->post('https://souzpir.local/mfa/setup', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->post('https://souzpir.local/mfa/setup', ['password' => 'secure-password-example'])->assertRedirect(route('mfa.setup'));
        $secret = session('auth.mfa_setup.secret');
        $code = app(Totp::class)->at($secret, intdiv(now()->timestamp, 30));
        $this->post('https://souzpir.local/mfa/setup/confirm', ['code' => $code])->assertRedirect(route('auth.ready'));
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
        $this->get('/invitations/open')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->exchangeInvitation($token)->assertOk();
        $this->get('/invitations/show')->assertOk();
        $this->post('/invitations/register', ['name' => 'Member', 'password' => 'another-secure-password', 'password_confirmation' => 'another-secure-password', 'terms' => true])->assertRedirect(route('verification.notice'));
        $member = User::where('email', 'member@example.test')->firstOrFail();
        $this->assertDatabaseMissing('memberships', ['user_id' => $member->id]);
        $this->assertAuthenticatedAs($member);
        $this->assertDatabaseHas('auth_session_registry', ['user_id' => $member->id, 'revoked_at' => null]);
        $this->assertDatabaseHas('auth_session_registry', ['session_hash' => hash_hmac('sha256', 'auth-session:'.session()->getId(), (string) config('app.key'))]);
        self::assertSame($member->auth_version, session('auth.version'));
        $registryRow = DB::table('auth_session_registry')->where('user_id', $member->id)->first();
        self::assertTrue(now()->lessThan($registryRow->expires_at));
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->post('/invitations/accept')->assertRedirect(route('verification.notice'));
        $member->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($member->fresh());
        $this->post('/invitations/accept')->assertRedirect(route('auth.ready'));
        $this->assertDatabaseHas('memberships', ['organization_id' => $org->id, 'user_id' => $member->id, 'role' => 'member']);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $member->id, 'action' => 'account.invitation_accepted']);
        $this->get('/invitations/show')->assertNotFound();
        $this->assertDatabaseCount('organizations', 1);
    }

    public function test_invitation_exchange_strips_token_and_survives_login_without_putting_it_in_the_session(): void
    {
        [$org, $owner, $brand] = $this->fixture();
        $guest = User::create(['name' => 'Invited', 'email' => 'invited@example.test', 'password' => 'another-secure-password']);
        $guest->forceFill(['email_verified_at' => now()])->save();
        $token = str_repeat('Q', 64);
        $invitation = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $owner->id), fn () => Invitation::create([
            'email' => $guest->email, 'role' => 'member', 'brand_ids' => [$brand->id],
            'token_hash' => hash('sha256', $token), 'invited_by' => $owner->id, 'expires_at' => now()->addDay(),
        ]));
        $this->exchangeInvitation($token)->assertOk()->assertJson(['ok' => true]);
        self::assertSame($invitation->id, app(InvitationExchangeGrants::class)->invitationId(request()));
        self::assertStringNotContainsString($token, serialize(session()->all()));
        self::assertStringNotContainsString($token, request()->getContent());
        self::assertStringNotContainsString($token, json_encode(request()->all()));
        $show = $this->get('/invitations/show')->assertOk();
        self::assertStringNotContainsString($token, $show->getContent());
        $login = $this->post('/login', ['email' => $guest->email, 'password' => 'another-secure-password']);
        $login->assertRedirect(route('invitation.show'));
        self::assertSame($invitation->id, app(InvitationExchangeGrants::class)->invitationId(request()));
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->post('/invitations/accept')->assertRedirect(route('auth.ready'));
        self::assertNull(session('auth.invitation_id'));
        $this->assertDatabaseHas('memberships', ['organization_id' => $org->id, 'user_id' => $guest->id]);
    }

    public function test_invitation_session_survives_mfa_and_cannot_use_arbitrary_identifier(): void
    {
        [$org, $owner, $brand] = $this->fixture();
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $guest = User::create(['name' => 'Invited MFA', 'email' => 'invited-mfa@example.test', 'password' => 'another-secure-password']);
        $guest->forceFill(['email_verified_at' => now(), 'mfa_secret' => $secret, 'mfa_confirmed_at' => now()])->save();
        $token = str_repeat('M', 64);
        $invitation = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $owner->id), fn () => Invitation::create([
            'email' => $guest->email, 'role' => 'member', 'brand_ids' => [$brand->id],
            'token_hash' => hash('sha256', $token), 'invited_by' => $owner->id, 'expires_at' => now()->addDay(),
        ]));
        try {
            app(InvitationAcceptance::class)->acceptSession($guest, Request::create('/invitations/accept', 'POST', ['id' => $invitation->id]));
            self::fail('Unbound invitation ID was accepted.');
        } catch (HttpException $error) {
            self::assertSame(404, $error->getStatusCode());
        }
        $this->exchangeInvitation($token)->assertOk();
        $this->post('/login', ['email' => $guest->email, 'password' => 'another-secure-password'])->assertRedirect(route('mfa.challenge'));
        $this->withCookie(config('session.cookie'), session()->getId());
        $code = app(Totp::class)->at($secret, intdiv(now()->timestamp, 30));
        $this->post('/mfa/challenge', ['code' => $code])->assertRedirect(route('invitation.show'));
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->get('/invitations/show')->assertOk();
        $this->post('/invitations/accept')->assertRedirect(route('auth.ready'));
        $this->assertDatabaseHas('memberships', ['organization_id' => $org->id, 'user_id' => $guest->id]);
        self::assertNull(session('auth.invitation_id'));
    }

    public function test_invitation_session_rechecks_revocation_expiry_and_wrong_account(): void
    {
        [$org, $owner, $brand] = $this->fixture();
        $token = str_repeat('W', 64);
        $invitation = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $owner->id), fn () => Invitation::create([
            'email' => 'invited-other@example.test', 'role' => 'member', 'brand_ids' => [$brand->id],
            'token_hash' => hash('sha256', $token), 'invited_by' => $owner->id, 'expires_at' => now()->addDay(),
        ]));
        $this->exchangeInvitation($token)->assertOk();
        DB::table('invitations')->where('id', $invitation->id)->update(['revoked_at' => now()]);
        $this->get('/invitations/show')->assertNotFound();
        $this->post('/invitations/register', ['name' => 'Synthetic'])->assertNotFound();
        DB::table('invitations')->where('id', $invitation->id)->update(['revoked_at' => null, 'expires_at' => now()->subSecond()]);
        $this->get('/invitations/show')->assertNotFound();
        DB::table('invitations')->where('id', $invitation->id)->update(['expires_at' => now()->addDay()]);
        $this->post('/login', ['email' => $owner->email, 'password' => 'secure-password-example'])->assertRedirect(route('invitation.show'));
        $this->withCookie(config('session.cookie'), session()->getId());
        $response = $this->get('/invitations/show')->assertNotFound();
        self::assertStringNotContainsString('invited-other@example.test', $response->getContent());
        $this->post('/logout')->assertRedirect(route('login'));
        self::assertNull(session('auth.invitation_id'));
        $this->get('/invitations/show')->assertNotFound();
        $this->assertDatabaseMissing('memberships', ['organization_id' => $org->id, 'role' => 'member']);
    }

    public function test_invitation_exchange_scrubs_bearer_for_encoded_route_variants(): void
    {
        $this->fixture();
        $token = str_repeat('V', 64);
        foreach (['/invitations/%65xchange', '/invitations%2Fexchange', '/%69nvitations/exchange'] as $path) {
            $response = $this->postJson($path, ['token' => $token]);
            $response->assertNotFound();
            self::assertStringNotContainsString($token, $response->getContent());
            self::assertStringNotContainsString($token, request()->getContent());
            self::assertStringNotContainsString($token, json_encode(request()->all()));
        }
    }

    public function test_invitation_exchange_bounds_and_clears_oversized_and_malformed_bodies(): void
    {
        foreach ([
            json_encode(['token' => str_repeat('Z', 64), 'padding' => str_repeat('X', 5000)], JSON_THROW_ON_ERROR),
            '{"token":"synthetic-invalid"',
        ] as $body) {
            $request = Request::create('/invitations/exchange', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
            $called = false;
            $response = app(InvitationSecretBoundary::class)->handle($request, function () use (&$called) {
                $called = true;

                return response()->json(['ok' => true]);
            });
            self::assertSame(404, $response->getStatusCode());
            self::assertFalse($called);
            self::assertSame('{}', $request->getContent());
            self::assertSame([], $request->json()->all());
        }
    }

    public function test_invitation_exchange_rejects_invalid_token_and_sanitizes_exception_traces(): void
    {
        $this->fixture();
        $token = str_repeat('S', 64);
        $response = $this->postJson('/invitations/exchange', ['token' => $token]);
        $response->assertNotFound();
        self::assertStringNotContainsString($token, $response->getContent());
        self::assertStringNotContainsString($token, request()->getContent());
        self::assertStringNotContainsString($token, serialize(session()->all()));
        self::assertNull(session('auth.invitation_id'));
        $this->get('/invitations/'.$token)->assertNotFound();

        $request = Request::create('/invitations/exchange', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['token' => $token]));
        $trace = '';
        $response = app(InvitationSecretBoundary::class)->handle($request, function (Request $safe) use (&$trace): never {
            $error = new \RuntimeException('Synthetic failure');
            $trace = (string) $error.' '.json_encode([$safe->all(), $safe->getContent(), $safe->attributes->all()]);
            throw $error;
        });
        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString($token, $response->getContent());
        self::assertStringNotContainsString($token, $request->getContent());
        self::assertStringNotContainsString($token, json_encode($request->all()));
        self::assertStringNotContainsString($token, $trace);
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
