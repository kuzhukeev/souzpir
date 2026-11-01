<?php

namespace Tests\Feature\Shell\Profile;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedDatabaseMigrations;
use Tests\Support\MfaTestDatabase;
use Tests\TestCase;

final class MfaRecoveryLogoutRaceTest extends TestCase
{
    use IsolatedDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        MfaTestDatabase::assert();
    }

    public static function orders(): array
    {
        return [['issue'], ['logout']];
    }

    #[DataProvider('orders')]
    public function test_issuance_and_logout_serialize_with_the_correct_outcome(string $firstOperation): void
    {
        $user = User::create(['name' => 'Synthetic', 'email' => 'recovery-logout@example.test', 'password' => 'fixture-password-only']);
        $user->forceFill(['mfa_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'mfa_confirmed_at' => now(), 'email_verified_at' => now()])->save();
        $user->refresh();
        $org = Organization::create(['name' => 'Synthetic MFA race', 'owner_user_id' => $user->id]);
        [$brand, $membership] = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $user->id), function () use ($user): array {
            return [Brand::create(['name' => 'Synthetic brand']), Membership::create(['user_id' => $user->id, 'role' => 'owner'])];
        });
        $sessionId = Str::random(40);
        $sessionHash = hash_hmac('sha256', 'auth-session:'.$sessionId, (string) config('app.key'));
        DB::table('auth_session_registry')->insert(['session_hash' => $sessionHash, 'user_id' => $user->id, 'auth_version' => $user->auth_version,
            'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        $fixtureRequest = Request::create('https://souzpir.local/profile/security/mfa/recovery', 'POST');
        $fixtureSession = new Store('test', new ArraySessionHandler(120), $sessionId);
        $fixtureSession->put('auth.version', $user->auth_version);
        $fixtureRequest->setLaravelSession($fixtureSession);
        $fixtureRequest->setUserResolver(fn () => $user);
        $selection = app(AuthSessionRegistry::class)->withinActive($fixtureRequest,
            fn () => app(SessionBrandSelection::class)->initialize($fixtureRequest, $org->id, $brand->id));
        $payload = ['organization' => $org->id, 'brand' => $brand->id, 'membership' => $membership->id, 'user' => $user->id,
            'session' => $sessionId, 'version' => $user->auth_version, 'brand_revision' => (string) $selection->brand_revision];
        $first = $second = null;
        try {
            $first = $this->start([...$payload, 'operation' => $firstOperation, 'hold' => true]);
            self::assertMatchesRegularExpression('/^ready:\d+$/', trim((string) fgets($first[1][1])));
            self::assertSame("applied\n", fgets($first[1][1]));
            $second = $this->start([...$payload, 'operation' => $firstOperation === 'issue' ? 'logout' : 'issue', 'hold' => false]);
            self::assertMatchesRegularExpression('/^ready:(\d+)$/', trim((string) ($ready = fgets($second[1][1]))));
            preg_match('/^ready:(\d+)$/', trim($ready), $matches);
            $deadline = microtime(true) + 10;
            do {
                $blocked = DB::table('pg_locks')->where('pid', (int) $matches[1])->where('granted', false)->exists();
                if (! $blocked) {
                    usleep(10000);
                }
            } while (! $blocked && microtime(true) < $deadline);
            self::assertTrue($blocked, 'Second operation must reach the database lock while the first transaction is open.');
            fwrite($first[1][0], "continue\n");
            fflush($first[1][0]);
            self::assertSame("committed\n", fgets($first[1][1]));
            $secondResult = trim((string) fgets($second[1][1]));
            if ($firstOperation === 'logout') {
                self::assertSame('denied', $secondResult);
                self::assertSame(0, DB::table('mfa_recovery_codes')->where('user_id', $user->id)->count());
                self::assertSame(0, DB::table('audit_logs')->where('action', 'account.mfa.recovery_codes_rotated')->count());
                self::assertSame($payload['version'], $user->fresh()->auth_version);
            } else {
                self::assertSame('applied', $secondResult, 'Concurrent logout must still revoke the session after a successful issuance.');
                self::assertSame("committed\n", fgets($second[1][1]));
                self::assertSame(10, DB::table('mfa_recovery_codes')->where('user_id', $user->id)->count());
                self::assertSame(1, DB::table('audit_logs')->where('action', 'account.mfa.recovery_codes_rotated')->count());
                self::assertSame(1, DB::table('audit_logs')->where('action', 'account.mfa.recovery_codes_rotated')
                    ->where('organization_id', $org->id)->where('actor_id', $user->id)->where('subject_id', $user->id)->count());
                self::assertSame($payload['version'] + 1, $user->fresh()->auth_version);
            }
            self::assertNotNull(DB::table('auth_session_registry')->where('session_hash', $sessionHash)->value('revoked_at'));
            foreach ([$payload['version'], $user->fresh()->auth_version] as $version) {
                $probe = $this->start([...$payload, 'version' => $version, 'operation' => 'probe', 'hold' => false]);
                try {
                    self::assertMatchesRegularExpression('/^ready:\d+$/', trim((string) fgets($probe[1][1])));
                    self::assertSame("denied\n", fgets($probe[1][1]));
                } finally {
                    foreach ($probe[1] as $pipe) {
                        fclose($pipe);
                    }
                    if (proc_get_status($probe[0])['running']) {
                        proc_terminate($probe[0]);
                    }
                    proc_close($probe[0]);
                }
            }
        } finally {
            foreach ([$first, $second] as $worker) {
                if ($worker === null) {
                    continue;
                }
                [$process, $pipes] = $worker;
                if (is_resource($pipes[0])) {
                    @fwrite($pipes[0], "continue\n");
                    fclose($pipes[0]);
                }
                foreach ([1, 2] as $index) {
                    fclose($pipes[$index]);
                }
                if (proc_get_status($process)['running']) {
                    proc_terminate($process);
                }
                proc_close($process);
            }
        }
    }

    public function test_stale_logout_cannot_authorize_actions_or_revoke_another_users_lease(): void
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'owner-recovery@example.test', 'password' => 'fixture-password-only']);
        $other = User::create(['name' => 'Other', 'email' => 'other-recovery@example.test', 'password' => 'fixture-password-only']);
        $owner->forceFill(['auth_version' => 1])->save();
        $sessionId = Str::random(40);
        $sessionHash = hash_hmac('sha256', 'auth-session:'.$sessionId, (string) config('app.key'));
        DB::table('auth_session_registry')->insert(['session_hash' => $sessionHash, 'user_id' => $owner->id, 'auth_version' => 1,
            'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        $session = new Store('recovery-logout', new ArraySessionHandler(120), $sessionId);
        $session->start();
        $session->put('auth.version', 0);
        $request = Request::create('/logout', 'POST');
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $other);
        try {
            app(AuthSessionRegistry::class)->withinLogout($request, fn () => app(AuthSessionRegistry::class)->revokeCurrent($request));
            self::fail('A different user must not revoke the lease.');
        } catch (AuthenticationException) {
            self::assertNull(DB::table('auth_session_registry')->where('session_hash', $sessionHash)->value('revoked_at'));
        }
        $request->setUserResolver(fn () => $owner);
        foreach ([-1, 2] as $invalidVersion) {
            $session->put('auth.version', $invalidVersion);
            try {
                app(AuthSessionRegistry::class)->withinLogout($request, fn () => app(AuthSessionRegistry::class)->revokeCurrent($request));
                self::fail('Invalid session versions must not revoke the lease.');
            } catch (AuthenticationException) {
                self::assertNull(DB::table('auth_session_registry')->where('session_hash', $sessionHash)->value('revoked_at'));
            }
        }
        $session->put('auth.version', 0);
        try {
            app(AuthSessionRegistry::class)->withinActive($request, fn () => null);
            self::fail('An old snapshot must not authorize ordinary actions.');
        } catch (AuthenticationException) {
            self::assertNull(DB::table('auth_session_registry')->where('session_hash', $sessionHash)->value('revoked_at'));
        }
        DB::table('auth_session_registry')->where('session_hash', $sessionHash)->update(['auth_version' => 0]);
        try {
            app(AuthSessionRegistry::class)->withinLogout($request, fn () => app(AuthSessionRegistry::class)->revokeCurrent($request));
            self::fail('A lease invalidated by an account-wide change is not current.');
        } catch (AuthenticationException) {
            self::assertNull(DB::table('auth_session_registry')->where('session_hash', $sessionHash)->value('revoked_at'));
        }
        DB::table('auth_session_registry')->where('session_hash', $sessionHash)->update(['auth_version' => 1]);
        DB::table('auth_session_registry')->where('session_hash', $sessionHash)->update(['expires_at' => now()->subSecond()]);
        try {
            app(AuthSessionRegistry::class)->withinLogout($request, fn () => app(AuthSessionRegistry::class)->revokeCurrent($request));
            self::fail('An expired lease must not authorize logout.');
        } catch (AuthenticationException) {
            self::assertNull(DB::table('auth_session_registry')->where('session_hash', $sessionHash)->value('revoked_at'));
        }
        DB::table('auth_session_registry')->where('session_hash', $sessionHash)->update(['expires_at' => now()->addHour(), 'remember_fingerprint' => str_repeat('a', 64)]);
        try {
            app(AuthSessionRegistry::class)->withinLogout($request, fn () => app(AuthSessionRegistry::class)->revokeCurrent($request));
            self::fail('A mismatched remember token must not authorize logout.');
        } catch (AuthenticationException) {
            self::assertNull(DB::table('auth_session_registry')->where('session_hash', $sessionHash)->value('revoked_at'));
        }
        DB::table('auth_session_registry')->where('session_hash', $sessionHash)->update(['remember_fingerprint' => null]);
        app(AuthSessionRegistry::class)->withinLogout($request, fn () => app(AuthSessionRegistry::class)->revokeCurrent($request));
        self::assertNotNull(DB::table('auth_session_registry')->where('session_hash', $sessionHash)->value('revoked_at'));
    }

    public function test_logout_http_route_revokes_its_current_lease_with_a_stale_session_snapshot(): void
    {
        $user = User::create(['name' => 'Synthetic', 'email' => 'http-logout@example.test', 'password' => 'fixture-password-only']);
        $user->forceFill(['auth_version' => 1])->save();
        $this->actingAs($user);
        $this->startSession();
        $this->withSession(['auth.version' => 0]);
        $sessionId = session()->getId();
        $sessionHash = hash_hmac('sha256', 'auth-session:'.$sessionId, (string) config('app.key'));
        DB::table('auth_session_registry')->insert(['session_hash' => $sessionHash, 'user_id' => $user->id, 'auth_version' => 1,
            'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        $this->withCookie(config('session.cookie'), $sessionId)->post('/logout')->assertRedirect(route('login'));
        self::assertNotNull(DB::table('auth_session_registry')->where('session_hash', $sessionHash)->value('revoked_at'));
        $this->assertGuest();
    }

    private function start(array $payload): array
    {
        $command = [PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/recovery-logout-worker.php'];
        $environment = array_merge(getenv(), ['APP_ENV' => 'testing', 'CABINET_ORGANIZATION_ID' => $payload['organization']]);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $environment);
        self::assertIsResource($process);
        stream_set_timeout($pipes[1], 25);
        fwrite($pipes[0], json_encode($payload, JSON_THROW_ON_ERROR)."\n");
        fflush($pipes[0]);

        return [$process, $pipes];
    }
}
