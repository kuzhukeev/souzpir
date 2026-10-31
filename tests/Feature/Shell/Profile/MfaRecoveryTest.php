<?php

namespace Tests\Feature\Shell\Profile;

use App\Shell\Auth\MfaChallenge;
use App\Shell\Auth\MfaCode;
use App\Shell\Auth\MfaCodeBoundary;
use App\Shell\Auth\MfaRecoveryTransport;
use App\Shell\Auth\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Tenancy\TenancyTestCase;

final class MfaRecoveryTest extends TenancyTestCase
{
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    protected function beforeRefreshingDatabase(): void
    {
        $expected = basename(dirname(base_path())) === '.worktrees' ? 'souzpir_test_'.str_replace('-', '_', basename(base_path())) : 'souzpir_test_main';
        if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== $expected
            || DB::connection()->getDatabaseName() !== config('runtime.test_database')
            || DB::connection()->getDatabaseName() === config('runtime.preview_database')) {
            throw new \LogicException('Isolated test database required.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withServerVariables(['SERVER_ADDR' => '127.0.0.1', 'REMOTE_ADDR' => '127.0.0.1']);
        Http::preventStrayRequests();
    }

    public function test_issue_then_log_in_once_with_a_recovery_code(): void
    {
        [$user, , $brand] = $this->cabinet();
        $user->forceFill(['mfa_secret' => self::SECRET, 'mfa_confirmed_at' => now()])->save();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $totp = app(Totp::class)->at(self::SECRET, intdiv(now()->timestamp, 30));
        $response = $this->postJson('https://souzpir.local/profile/security/mfa/recovery', ['code' => $totp])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $codes = $response->json('codes');
        self::assertCount(10, $codes);
        self::assertCount(10, array_unique($codes));
        $rows = json_encode(DB::table('mfa_recovery_codes')->get(), JSON_THROW_ON_ERROR);
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^([A-F0-9]{8}-){3}[A-F0-9]{8}$/D', $code);
            self::assertStringNotContainsString($code, $rows);
            self::assertStringNotContainsString(str_replace('-', '', strtolower($code)), $rows);
            self::assertStringNotContainsString($code, serialize(session()->all()));
        }
        self::assertStringNotContainsString($totp, request()->getContent());
        $this->post('/logout')->assertRedirect(route('login'));
        $this->post('/login', ['email' => $user->email, 'password' => 'test-only-password'])->assertRedirect(route('mfa.challenge'));
        $this->post('http://souzpir.local/mfa/challenge', ['code' => $codes[0]])->assertSessionHasErrors('code');
        self::assertSame(0, DB::table('mfa_recovery_codes')->whereNotNull('used_at')->count());
        $this->post('https://souzpir.local/mfa/challenge', ['code' => $codes[0]])->assertRedirect(route('auth.ready'));
        $this->assertDatabaseCount('mfa_recovery_codes', 10);
        self::assertSame(1, DB::table('mfa_recovery_codes')->whereNotNull('used_at')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'account.mfa.recovery_code_used')->count());
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->post('/logout')->assertRedirect(route('login'));
        $this->post('/login', ['email' => $user->email, 'password' => 'test-only-password'])->assertRedirect(route('mfa.challenge'));
        $this->post('https://souzpir.local/mfa/challenge', ['code' => $codes[0]])->assertSessionHasErrors('code');
        self::assertNull(session()->getOldInput('code'));
        $this->post('https://souzpir.local/mfa/challenge', ['code' => $codes[1]])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertAuthenticatedAs($user->fresh());
        Http::assertNothingSent();
    }

    public function test_issue_rejects_insecure_origin_and_mfa_replay(): void
    {
        [$user, , $brand] = $this->cabinet();
        $user->forceFill(['mfa_secret' => self::SECRET, 'mfa_confirmed_at' => now()])->save();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $totp = app(Totp::class)->at(self::SECRET, intdiv(now()->timestamp, 30));
        $this->postJson('http://souzpir.local/profile/security/mfa/recovery', ['code' => $totp])->assertUnprocessable();
        $this->assertDatabaseCount('mfa_recovery_codes', 0);
        $this->postJson('https://souzpir.local/profile/security/mfa/recovery', ['code' => $totp])->assertOk();
        $this->postJson('https://souzpir.local/profile/security/mfa/recovery', ['code' => $totp])->assertUnprocessable();
        $this->assertDatabaseCount('mfa_recovery_codes', 10);
    }

    public function test_input_boundary_erases_raw_code_before_downstream_and_exception_trace(): void
    {
        $code = 'AB123456-CD123456-EF123456-AB123456';
        $request = Request::create('/mfa/challenge', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['code' => $code]));
        $previous = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            app(MfaCodeBoundary::class)->handle($request, function (Request $safe) use ($code): never {
                self::assertStringNotContainsString($code, $safe->getContent());
                self::assertStringNotContainsString($code, json_encode([$safe->all(), $safe->attributes->all()]));
                throw new \RuntimeException('Synthetic downstream failure');
            });
            self::fail('Expected synthetic failure.');
        } catch (\RuntimeException $error) {
            self::assertStringNotContainsString($code, (string) $error);
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    public function test_totp_and_recovery_share_a_durable_account_limit(): void
    {
        [$user] = $this->cabinet();
        $user->forceFill(['mfa_secret' => self::SECRET, 'mfa_confirmed_at' => now()])->save();
        $user->refresh();
        $pending = ['user_id' => $user->id, 'version' => $user->auth_version, 'expires' => now()->addMinutes(5)->timestamp];
        $invalid = MfaCode::parse('A1111111-A1111111-A1111111-A1111111');
        for ($i = 0; $i < 5; $i++) {
            self::assertNull(app(MfaChallenge::class)->verify($pending, $invalid));
        }
        $totp = app(Totp::class)->at(self::SECRET, intdiv(now()->timestamp, 30));
        self::assertNull(app(MfaChallenge::class)->verify($pending, MfaCode::parse($totp)));
        self::assertSame(5, DB::table('mfa_challenge_limits')->where('user_id', $user->id)->value('failures'));
        $this->travel(16)->minutes();
        $pending['expires'] = now()->addMinutes(5)->timestamp;
        $totp = app(Totp::class)->at(self::SECRET, intdiv(now()->timestamp, 30));
        self::assertNotNull(app(MfaChallenge::class)->verify($pending, MfaCode::parse($totp)));
        self::assertSame(0, DB::table('mfa_challenge_limits')->where('user_id', $user->id)->count());
    }

    public function test_reissuing_invalidates_previous_codes_and_stale_pending_login(): void
    {
        [$user, , $brand] = $this->cabinet();
        $user->forceFill(['mfa_secret' => self::SECRET, 'mfa_confirmed_at' => now()])->save();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $firstTotp = app(Totp::class)->at(self::SECRET, intdiv(now()->timestamp, 30));
        $first = $this->postJson('https://souzpir.local/profile/security/mfa/recovery', ['code' => $firstTotp])->assertOk()->json('codes');
        $version = $user->fresh()->auth_version;
        $this->travel(31)->seconds();
        $secondTotp = app(Totp::class)->at(self::SECRET, intdiv(now()->timestamp, 30));
        $second = $this->postJson('https://souzpir.local/profile/security/mfa/recovery', ['code' => $secondTotp])->assertOk()->json('codes');
        self::assertNotSame($first, $second);
        self::assertSame(10, DB::table('mfa_recovery_codes')->count());
        $stale = ['user_id' => $user->id, 'version' => $version, 'expires' => now()->addMinute()->timestamp];
        self::assertNull(app(MfaChallenge::class)->verify($stale, MfaCode::parse($second[0])));
        $fresh = [...$stale, 'version' => $user->fresh()->auth_version];
        self::assertNull(app(MfaChallenge::class)->verify($fresh, MfaCode::parse($first[0])));
        self::assertNotNull(app(MfaChallenge::class)->verify($fresh, MfaCode::parse($second[0])));
    }

    public function test_local_transport_requires_the_exact_host_direct_loopback_and_local_environment(): void
    {
        $request = Request::create('http://souzpir.local/profile/security/mfa/recovery', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        $environment = app()->environment();
        try {
            app()->instance('env', 'local');
            self::assertTrue(MfaRecoveryTransport::allows($request));
            $request->headers->set('X-Forwarded-Proto', 'http');
            self::assertFalse(MfaRecoveryTransport::allows($request));
            $request->headers->remove('X-Forwarded-Proto');
            $request->server->set('REMOTE_ADDR', '192.0.2.1');
            self::assertFalse(MfaRecoveryTransport::allows($request));
            $request->server->set('REMOTE_ADDR', '127.0.0.1');
            $request->headers->set('Host', 'example.test');
            self::assertFalse(MfaRecoveryTransport::allows($request));
            $request->headers->set('Host', 'souzpir.local');
            app()->instance('env', 'production');
            self::assertFalse(MfaRecoveryTransport::allows($request));
        } finally {
            app()->instance('env', $environment);
        }
    }

    public function test_local_page_and_issuance_are_restricted_and_not_inertia_responses(): void
    {
        [$user, , $brand] = $this->cabinet();
        $user->forceFill(['mfa_secret' => self::SECRET, 'mfa_confirmed_at' => now()])->save();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $this->get('http://souzpir.local/profile/security/mfa/recovery-local')->assertNotFound();
        $environment = app()->environment();
        try {
            app()->instance('env', 'local');
            $page = $this->get('http://souzpir.local/profile/security/mfa/recovery-local')->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private');
            self::assertStringContainsString('frame-ancestors \'none\'', $page->headers->get('Content-Security-Policy'));
            self::assertStringNotContainsString(self::SECRET, $page->getContent());
            self::assertStringNotContainsString('data-page=', $page->getContent());
            $totp = app(Totp::class)->at(self::SECRET, intdiv(now()->timestamp, 30));
            $this->withHeader('X-CSRF-TOKEN', session()->token())
                ->postJson('http://souzpir.local/profile/security/mfa/recovery', ['code' => $totp])->assertOk()->assertJsonCount(10, 'codes');
        } finally {
            app()->instance('env', $environment);
        }
    }
}
