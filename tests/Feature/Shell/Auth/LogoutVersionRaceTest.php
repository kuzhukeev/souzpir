<?php

namespace Tests\Feature\Shell\Auth;

use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedAuthDatabase;
use Tests\TestCase;

final class LogoutVersionRaceTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        IsolatedAuthDatabase::assert();
    }

    public static function orders(): array
    {
        return [['advance'], ['logout']];
    }

    #[DataProvider('orders')]
    public function test_logout_serializes_with_a_security_version_change(string $firstOperation): void
    {
        $user = User::create(['name' => 'Synthetic', 'email' => 'version-logout@example.test', 'password' => 'fixture-password-only']);
        $user->refresh();
        $sessionId = Str::random(40);
        $sessionHash = hash_hmac('sha256', 'auth-session:'.$sessionId, (string) config('app.key'));
        DB::table('auth_session_registry')->insert(['session_hash' => $sessionHash, 'user_id' => $user->id, 'auth_version' => $user->auth_version,
            'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        $payload = ['user' => $user->id, 'session' => $sessionId, 'version' => $user->auth_version];
        $first = $second = null;
        try {
            $first = $this->start([...$payload, 'operation' => $firstOperation, 'hold' => true]);
            self::assertMatchesRegularExpression('/^ready:\d+$/', trim((string) fgets($first[1][1])));
            self::assertSame("applied\n", fgets($first[1][1]));
            $second = $this->start([...$payload, 'operation' => $firstOperation === 'advance' ? 'logout' : 'advance', 'hold' => false]);
            self::assertMatchesRegularExpression('/^ready:(\d+)$/', trim((string) ($ready = fgets($second[1][1]))));
            preg_match('/^ready:(\d+)$/', trim($ready), $matches);
            $deadline = microtime(true) + 10;
            do {
                $blocked = DB::table('pg_locks')->where('pid', (int) $matches[1])->where('granted', false)->exists();
                if (! $blocked) {
                    usleep(10000);
                }
            } while (! $blocked && microtime(true) < $deadline);
            self::assertTrue($blocked, 'Second process must wait on the first transaction.');
            fwrite($first[1][0], "continue\n");
            fflush($first[1][0]);
            self::assertSame("committed\n", fgets($first[1][1]));
            $secondResult = trim((string) fgets($second[1][1]));
            if ($firstOperation === 'logout') {
                self::assertSame('denied', $secondResult);
                self::assertSame($payload['version'], $user->fresh()->auth_version);
            } else {
                self::assertSame('applied', $secondResult);
                self::assertSame("committed\n", fgets($second[1][1]));
                self::assertSame($payload['version'] + 1, $user->fresh()->auth_version);
            }
            self::assertNotNull(DB::table('auth_session_registry')->where('session_hash', $sessionHash)->value('revoked_at'));
            foreach ([$payload['version'], $user->fresh()->auth_version] as $version) {
                $probe = $this->start([...$payload, 'version' => $version, 'operation' => 'probe', 'hold' => false]);
                try {
                    self::assertMatchesRegularExpression('/^ready:\d+$/', trim((string) fgets($probe[1][1])));
                    self::assertSame("denied\n", fgets($probe[1][1]));
                } finally {
                    $this->stop($probe);
                }
            }
        } finally {
            foreach ([$first, $second] as $worker) {
                if ($worker !== null) {
                    $this->stop($worker);
                }
            }
        }
    }

    public function test_stale_session_can_only_revoke_its_own_current_lease(): void
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'owner-logout@example.test', 'password' => 'fixture-password-only']);
        $other = User::create(['name' => 'Other', 'email' => 'other-logout@example.test', 'password' => 'fixture-password-only']);
        $owner->forceFill(['auth_version' => 1])->save();
        $sessionId = Str::random(40);
        $hash = hash_hmac('sha256', 'auth-session:'.$sessionId, (string) config('app.key'));
        DB::table('auth_session_registry')->insert(['session_hash' => $hash, 'user_id' => $owner->id, 'auth_version' => 1,
            'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        $session = new Store('version-logout', new ArraySessionHandler(120), $sessionId);
        $session->start();
        $session->put('auth.version', 0);
        $request = Request::create('/logout', 'POST');
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $other);
        $this->assertDenied(fn () => app(AuthSessionRegistry::class)->withinLogout($request, fn () => app(AuthSessionRegistry::class)->revokeCurrent($request)));
        $request->setUserResolver(fn () => $owner);
        $this->assertDenied(fn () => app(AuthSessionRegistry::class)->withinActive($request, fn () => null));
        foreach ([-1, 2] as $version) {
            $session->put('auth.version', $version);
            $this->assertDenied(fn () => app(AuthSessionRegistry::class)->withinLogout($request, fn () => null));
        }
        $session->put('auth.version', 0);
        foreach ([['auth_version' => 0], ['expires_at' => now()->subSecond()], ['remember_fingerprint' => str_repeat('a', 64)]] as $invalid) {
            DB::table('auth_session_registry')->where('session_hash', $hash)->update($invalid);
            $this->assertDenied(fn () => app(AuthSessionRegistry::class)->withinLogout($request, fn () => null));
            DB::table('auth_session_registry')->where('session_hash', $hash)->update(['auth_version' => 1, 'expires_at' => now()->addHour(), 'remember_fingerprint' => null]);
        }
        self::assertNull(DB::table('auth_session_registry')->where('session_hash', $hash)->value('revoked_at'));
        app(AuthSessionRegistry::class)->withinLogout($request, fn () => app(AuthSessionRegistry::class)->revokeCurrent($request));
        self::assertNotNull(DB::table('auth_session_registry')->where('session_hash', $hash)->value('revoked_at'));
        $this->assertDenied(fn () => app(AuthSessionRegistry::class)->withinLogout($request, fn () => null));
    }

    public function test_http_logout_accepts_only_the_current_lease_after_a_version_change(): void
    {
        $user = User::create(['name' => 'Synthetic', 'email' => 'http-logout@example.test', 'password' => 'fixture-password-only']);
        $user->forceFill(['auth_version' => 1])->save();
        $this->actingAs($user);
        $this->startSession();
        $this->withSession(['auth.version' => 0]);
        $sessionId = session()->getId();
        $hash = hash_hmac('sha256', 'auth-session:'.$sessionId, (string) config('app.key'));
        DB::table('auth_session_registry')->insert(['session_hash' => $hash, 'user_id' => $user->id, 'auth_version' => 1,
            'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        $this->withCookie(config('session.cookie'), $sessionId)->post('/logout')->assertRedirect(route('login'));
        self::assertNotNull(DB::table('auth_session_registry')->where('session_hash', $hash)->value('revoked_at'));
        $this->assertGuest();
        $this->withCookie(config('session.cookie'), $sessionId)->get('/auth/ready')->assertRedirect(route('login'));
    }

    public function test_http_logout_of_expired_session_clears_browser_without_revoking_other_session(): void
    {
        $user = User::create(['name' => 'Synthetic', 'email' => 'expired-logout@example.test', 'password' => 'fixture-password-only']);
        $user->forceFill(['remember_token' => 'synthetic-remember-token'])->save();
        $this->actingAs($user);
        $this->startSession();
        $this->withSession(['auth.version' => 0]);
        $sessionId = session()->getId();
        $hash = hash_hmac('sha256', 'auth-session:'.$sessionId, (string) config('app.key'));
        $otherHash = hash_hmac('sha256', 'auth-session:'.Str::random(40), (string) config('app.key'));
        foreach ([$hash => now()->subMinute(), $otherHash => now()->addHour()] as $id => $expiry) {
            DB::table('auth_session_registry')->insert(['session_hash' => $id, 'user_id' => $user->id, 'auth_version' => 0,
                'expires_at' => $expiry, 'created_at' => now(), 'updated_at' => now()]);
        }
        $grantHash = hash_hmac('sha256', 'invitation-session:'.$sessionId, (string) config('app.key'));
        $otherGrant = hash_hmac('sha256', 'invitation-session:'.Str::random(40), (string) config('app.key'));
        foreach ([$grantHash, $otherGrant] as $id) {
            DB::table('invitation_exchange_sessions')->insert(['session_hash' => $id, 'document_epoch' => str_repeat('a', 64),
                'sequence' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->withCookie(config('session.cookie'), $sessionId)->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
        self::assertNull(DB::table('auth_session_registry')->where('session_hash', $otherHash)->value('revoked_at'));
        self::assertFalse(DB::table('invitation_exchange_sessions')->where('session_hash', $grantHash)->exists());
        self::assertTrue(DB::table('invitation_exchange_sessions')->where('session_hash', $otherGrant)->exists());
        self::assertSame('synthetic-remember-token', $user->fresh()->remember_token);
        $this->withCookie(config('session.cookie'), $sessionId)->get('/auth/ready')->assertRedirect(route('login'));
    }

    private function assertDenied(\Closure $action): void
    {
        try {
            $action();
            self::fail('Operation must be denied.');
        } catch (AuthenticationException) {
            self::assertTrue(true);
        }
    }

    private function start(array $payload): array
    {
        $command = [PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/logout-version-worker.php'];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), array_merge(getenv(), ['APP_ENV' => 'testing']));
        self::assertIsResource($process);
        stream_set_timeout($pipes[1], 25);
        fwrite($pipes[0], json_encode($payload, JSON_THROW_ON_ERROR)."\n");
        fflush($pipes[0]);

        return [$process, $pipes];
    }

    private function stop(array $worker): void
    {
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
