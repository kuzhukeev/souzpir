<?php

namespace Tests\Feature\Shell\Profile;

use App\Shell\Auth\SecureAccountContext;
use App\Shell\Auth\Totp;
use App\Shell\Profile\EmailChangeSender;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SecureAccountContextTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withServerVariables(['SERVER_ADDR' => '127.0.0.1', 'REMOTE_ADDR' => '127.0.0.1']);
        Http::preventStrayRequests();
        Queue::fake();
        config(['features.E1-08.defaults.email_change_enabled' => true, 'app.url' => 'https://cabinet.example.test']);
        $this->app->instance(EmailChangeSender::class, new class implements EmailChangeSender
        {
            public function send(#[\SensitiveParameter] string $email, #[\SensitiveParameter] string $confirmationUrl): bool
            {
                throw new \LogicException('This test must not invoke delivery.');
            }
        });
    }

    public static function allowedOrigins(): array
    {
        return [
            'https' => ['https://souzpir.local'],
            'localhost' => ['http://localhost'],
            'ipv4 loopback' => ['http://127.0.0.1'],
            'ipv6 loopback' => ['http://[::1]'],
        ];
    }

    #[DataProvider('allowedOrigins')]
    public function test_secure_origins_preserve_mfa_setup_and_email_confirmation(string $origin): void
    {
        [$user, , $brand] = $this->cabinet();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $this->post($origin.'/profile/security/mfa/setup', ['password' => 'test-only-password'])->assertRedirect();
        $secret = session('auth.mfa_setup.secret');
        self::assertIsString($secret);
        $security = $this->get($origin.'/profile/security')->assertOk()->viewData('page');
        self::assertTrue($security['encryptHistory']);
        self::assertSame($secret, $security['props']['security']['setup']['secret']);
        $this->get($origin.'/mfa/setup')->assertOk()->assertViewHas('page', fn ($page) => $page['props']['secret'] === $secret && $page['encryptHistory']);

        $this->post($origin.'/profile/email', ['email' => 'replacement@example.test', 'current_password' => 'test-only-password'])->assertRedirect();
        $row = DB::table('profile_email_changes')->where('user_id', $user->id)->first();
        self::assertNotNull($row);
        $token = Crypt::decryptString($row->encrypted_token);
        $path = URL::temporarySignedRoute('profile.email.confirm', now()->addMinutes(30), ['change' => $row->id], absolute: false);
        $page = $this->get($origin.$path)->assertOk()->viewData('page');
        self::assertTrue($page['encryptHistory']);
        self::assertSame($path, $page['props']['emailConfirmationUrl']);
        Http::assertNothingSent();
    }

    public function test_insecure_host_cannot_create_mfa_secret_or_email_intent(): void
    {
        [$user, , $brand] = $this->cabinet();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $this->postJson('http://souzpir.local/profile/security/mfa/setup', ['password' => 'test-only-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('password')->assertJsonFragment(['password' => ['Для этой операции откройте кабинет по защищённому HTTPS-адресу.']]);
        self::assertNull(session('auth.mfa_setup'));
        $this->postJson('http://souzpir.local/mfa/setup', ['password' => 'test-only-password'])->assertUnprocessable()->assertJsonValidationErrors('password');
        self::assertNull(session('auth.mfa_setup'));
        $this->postJson('http://souzpir.local/profile/email', ['email' => 'replacement@example.test', 'current_password' => 'test-only-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('profile_email_changes', 0);
        Queue::assertNothingPushed();
        $profile = $this->get('http://souzpir.local/profile')->assertOk()->viewData('page')['props']['profile'];
        self::assertFalse($profile['email_change_available']);
        self::assertTrue($profile['email_confirmation_secure']);
    }

    public function test_insecure_reads_never_expose_an_existing_mfa_secret_and_cancellation_stays_available(): void
    {
        [$user, , $brand] = $this->cabinet();
        $secret = str_repeat('A', 32);
        $this->actingAs($user)->withSession(['brand_id' => $brand->id, 'auth.mfa_setup' => [
            'user_id' => $user->id, 'version' => $user->auth_version, 'secret' => $secret, 'expires' => now()->addMinutes(10)->timestamp,
        ]]);
        $response = $this->get('http://souzpir.local/profile/security')->assertOk();
        self::assertNull($response->viewData('page')['props']['security']['setup']);
        $response->assertDontSee($secret, false);
        $this->get('http://souzpir.local/mfa/setup')->assertForbidden()->assertDontSee($secret, false);
        $this->delete('http://souzpir.local/profile/security/mfa/setup')->assertRedirect();
        self::assertNull(session('auth.mfa_setup'));
    }

    public function test_valid_email_capability_cannot_be_shown_or_committed_over_insecure_http(): void
    {
        [$user, , $brand] = $this->cabinet();
        $oldEmail = $user->email;
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $this->post('https://souzpir.local/profile/email', ['email' => 'replacement@example.test', 'current_password' => 'test-only-password'])->assertRedirect();
        $row = DB::table('profile_email_changes')->where('user_id', $user->id)->first();
        $token = Crypt::decryptString($row->encrypted_token);
        $path = URL::temporarySignedRoute('profile.email.confirm', now()->addMinutes(30), ['change' => $row->id], absolute: false);
        $this->get('http://souzpir.local'.$path)->assertForbidden()->assertDontSee('emailConfirmationUrl', false)->assertDontSee($token, false);
        $this->post('http://souzpir.local'.$path, ['token' => $token])->assertForbidden();
        self::assertSame($oldEmail, $user->fresh()->email);
        $this->delete('http://souzpir.local/profile/email')->assertRedirect();
        $this->assertDatabaseHas('profile_email_changes', ['id' => $row->id, 'status' => 'cancelled']);
    }

    public function test_insecure_canonical_email_url_prevents_a_new_unusable_intent(): void
    {
        [$user, , $brand] = $this->cabinet();
        config(['app.url' => 'http://souzpir.local']);
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $page = $this->get('https://souzpir.local/profile')->assertOk()->viewData('page');
        self::assertFalse($page['props']['profile']['email_change_available']);
        self::assertFalse($page['props']['profile']['email_confirmation_secure']);
        $this->postJson('https://souzpir.local/profile/email', ['email' => 'replacement@example.test', 'current_password' => 'test-only-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('profile_email_changes', 0);
    }

    public function test_existing_mfa_can_be_disabled_without_disclosing_a_setup_secret_on_insecure_http(): void
    {
        [$user, , $brand] = $this->cabinet();
        $secret = str_repeat('A', 32);
        $user->forceFill(['mfa_secret' => $secret, 'mfa_confirmed_at' => now()])->save();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $this->post('http://souzpir.local/profile/security/mfa/disable', [
            'password' => 'test-only-password', 'code' => app(Totp::class)->at($secret, intdiv(now()->timestamp, 30)),
        ])->assertRedirect();
        self::assertNull($user->fresh()->mfa_confirmed_at);
    }

    public function test_lookalike_loopback_host_and_untrusted_forwarded_proto_do_not_bypass_guard(): void
    {
        [$user, , $brand] = $this->cabinet();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id])->withHeader('X-Forwarded-Proto', 'https');
        $this->postJson('http://localhost.attacker.test/profile/security/mfa/setup', ['password' => 'test-only-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        self::assertNull(session('auth.mfa_setup'));
    }

    public function test_local_http_requires_actual_loopback_on_both_sides_and_no_proxy_headers(): void
    {
        $make = static fn (array $server) => Request::create('http://127.0.0.1/profile/security', 'GET', [], [], [], $server);
        $check = SecureAccountContext::class;
        self::assertFalse($check::allows($make(['REMOTE_ADDR' => '198.51.100.10', 'SERVER_ADDR' => '203.0.113.20'])));
        self::assertFalse($check::allows($make(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '203.0.113.20'])));
        self::assertFalse($check::allows($make(['REMOTE_ADDR' => '198.51.100.10', 'SERVER_ADDR' => '127.0.0.1'])));
        self::assertFalse($check::allows($make(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.10'])));
        self::assertTrue($check::allows($make(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1'])));
        $this->app->instance('env', 'production');
        self::assertFalse($check::allows($make(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1'])));
    }

    public function test_forwarded_https_requires_an_explicit_trusted_proxy_even_for_forge_host(): void
    {
        $make = static fn () => Request::create('http://x.on-forge.com/profile/security', 'GET', [], [], [], [
            'REMOTE_ADDR' => '198.51.100.10', 'SERVER_ADDR' => '203.0.113.20', 'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);
        $middleware = new TrustProxies;
        config(['trustedproxy.proxies' => null]);
        $middleware->handle($make(), static function ($request): void {
            self::assertTrue($request->isSecure()); // Laravel's Forge auto-trust behavior.
            self::assertFalse(SecureAccountContext::allows($request));
        });
        config(['trustedproxy.proxies' => ['198.51.100.10']]);
        $middleware->handle($make(), static function ($request): void {
            self::assertTrue(SecureAccountContext::allows($request));
        });
        config(['trustedproxy.proxies' => ['*']]);
        $middleware->handle($make(), static function ($request): void {
            self::assertFalse(SecureAccountContext::allows($request));
        });
        Request::setTrustedProxies([], 0);
    }

    public function test_kernel_rejects_universal_or_invalid_proxy_networks_before_mfa_secret_creation(): void
    {
        [$user, , $brand] = $this->cabinet();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10', 'SERVER_ADDR' => '203.0.113.20'])
            ->withHeader('X-Forwarded-Proto', 'https');
        try {
            foreach ([['0.0.0.0/0'], ['::/0'], ['198.51.100.10', '0.0.0.0/0'], ['198.51.100.10', 'invalid']] as $proxies) {
                config(['trustedproxy.proxies' => $proxies]);
                $this->postJson('http://x.on-forge.com/profile/security/mfa/setup', ['password' => 'test-only-password'])
                    ->assertUnprocessable()->assertJsonValidationErrors('password');
                self::assertNull(session('auth.mfa_setup'));
            }
            $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::10', 'SERVER_ADDR' => '2001:db8::20']);
            config(['trustedproxy.proxies' => ['::/0']]);
            $this->postJson('http://cabinet.example.test/profile/security/mfa/setup', ['password' => 'test-only-password'])
                ->assertUnprocessable()->assertJsonValidationErrors('password');
            self::assertNull(session('auth.mfa_setup'));
            config(['trustedproxy.proxies' => ['2001:db8::10/128']]);
            $this->postJson('http://cabinet.example.test/profile/security/mfa/setup', ['password' => 'test-only-password'])
                ->assertRedirect();
            self::assertIsString(session('auth.mfa_setup.secret'));
        } finally {
            config(['trustedproxy.proxies' => null]);
            Request::setTrustedProxies([], 0);
        }
    }

    public function test_kernel_rejects_spoofed_or_ambiguous_forwarded_https_but_accepts_explicit_proxy_and_tls(): void
    {
        [$user, , $brand] = $this->cabinet();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        config(['trustedproxy.proxies' => ['198.51.100.10']]);
        try {
            Request::setTrustedProxies(['0.0.0.0/0'], Request::HEADER_X_FORWARDED_PROTO);
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.11', 'SERVER_ADDR' => '203.0.113.20'])
                ->withHeader('X-Forwarded-Proto', 'https')
                ->postJson('http://x.on-forge.com/profile/security/mfa/setup', ['password' => 'test-only-password'])
                ->assertUnprocessable()->assertJsonValidationErrors('password');
            self::assertNull(session('auth.mfa_setup'));

            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10', 'SERVER_ADDR' => '203.0.113.20'])
                ->withHeader('X-Forwarded-Proto', 'https,http')
                ->postJson('http://cabinet.example.test/profile/security/mfa/setup', ['password' => 'test-only-password'])
                ->assertUnprocessable()->assertJsonValidationErrors('password');
            self::assertNull(session('auth.mfa_setup'));
            $this->withHeader('X-Forwarded-Proto', 'https')->withHeader('Forwarded', 'proto=http')
                ->postJson('http://cabinet.example.test/profile/security/mfa/setup', ['password' => 'test-only-password'])
                ->assertUnprocessable()->assertJsonValidationErrors('password');
            self::assertNull(session('auth.mfa_setup'));

            $this->flushHeaders()->withHeader('X-Forwarded-Proto', 'https')
                ->postJson('http://cabinet.example.test/profile/security/mfa/setup', ['password' => 'test-only-password'])
                ->assertRedirect();
            self::assertIsString(session('auth.mfa_setup.secret'));
            session()->forget('auth.mfa_setup');
            $this->flushHeaders();
            config(['trustedproxy.proxies' => ['0.0.0.0/0']]);
            $this->postJson('https://cabinet.example.test/profile/security/mfa/setup', ['password' => 'test-only-password'])
                ->assertRedirect();
            self::assertIsString(session('auth.mfa_setup.secret'));
        } finally {
            config(['trustedproxy.proxies' => null]);
            Request::setTrustedProxies([], 0);
        }
    }
}
