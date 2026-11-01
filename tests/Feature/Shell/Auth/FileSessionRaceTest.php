<?php

namespace Tests\Feature\Shell\Auth;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\Totp;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\IsolatedAuthDatabase;
use Tests\Support\IsolatedDatabaseMigrations;
use Tests\TestCase;

#[Group('race')]
final class FileSessionRaceTest extends TestCase
{
    use IsolatedDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        IsolatedAuthDatabase::assert();
    }

    public function test_logout_remains_effective_after_parallel_request_restores_old_file(): void
    {
        $org = Organization::create(['name' => 'Race organization']);
        $user = User::create(['name' => 'Owner', 'email' => 'race-owner@example.test', 'password' => 'race-password-strong']);
        $user->forceFill(['email_verified_at' => now()])->save();
        app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $user->id), fn () => Membership::create(['user_id' => $user->id, 'role' => 'owner']));
        $base = base_path('.local/tmp/session-race-'.Str::random(16));
        $slow = null;
        try {
            $login = $this->finish($this->start('login', '-', $base, $org->id));
            self::assertSame(302, $login['status']);
            self::assertIsString($login['cookie']);
            $cookie = $login['cookie'];
            $slow = $this->start('slow', $cookie, $base, $org->id);
            $deadline = microtime(true) + 20;
            while (! is_file($base.'.loaded') && microtime(true) < $deadline) {
                usleep(10000);
            }
            self::assertFileExists($base.'.loaded', 'Parallel request did not load the session.');
            $logout = $this->finish($this->start('logout', $cookie, $base, $org->id));
            self::assertSame(302, $logout['status']);
            self::assertStringEndsWith('/login', $logout['location']);
            file_put_contents($base.'.release', '1');
            $slowResult = $this->finish($slow);
            $slow = null;
            self::assertSame(200, $slowResult['status']);
            // The stale in-flight request actually recreated the deleted file.
            self::assertFileExists(config('session.files').DIRECTORY_SEPARATOR.$login['session_id']);
            $oldCookie = $this->finish($this->start('view', $cookie, $base, $org->id));
            self::assertSame(302, $oldCookie['status']);
            self::assertStringEndsWith('/login', $oldCookie['location']);
        } finally {
            if ($slow !== null) {
                file_put_contents($base.'.release', '1');
                $this->finish($slow);
            }
            foreach ([$base.'.loaded', $base.'.release', $base.'.logout-started'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function test_sensitive_action_commits_before_concurrent_logout_and_is_then_revoked(): void
    {
        [$org, $user] = $this->createActor();
        $base = base_path('.local/tmp/session-lock-'.Str::random(16));
        $action = null;
        $logout = null;
        try {
            $login = $this->finish($this->start('login', '-', $base, $org->id));
            $action = $this->start('sensitive', $login['cookie'], $base, $org->id);
            $this->waitFile($base.'.locked');
            $logout = $this->start('logout', $login['cookie'], $base, $org->id);
            $this->waitFile($base.'.logout-started');
            usleep(250000);
            self::assertTrue(proc_get_status($logout[0])['running'], 'Logout must wait while the sensitive action holds the session lock.');
            file_put_contents($base.'.release', '1');
            self::assertSame(200, $this->finish($action)['status']);
            $action = null;
            self::assertSame(302, $this->finish($logout)['status']);
            $logout = null;
            self::assertSame('Action committed', $user->fresh()->name);
            self::assertStringEndsWith('/login', $this->finish($this->start('view', $login['cookie'], $base, $org->id))['location']);
        } finally {
            file_put_contents($base.'.release', '1');
            if ($action !== null) {
                $this->finish($action);
            }
            if ($logout !== null) {
                $this->finish($logout);
            }
            foreach ([$base.'.locked', $base.'.release', $base.'.logout-started'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function test_logout_first_denies_a_later_sensitive_action(): void
    {
        [$org, $user] = $this->createActor();
        $base = base_path('.local/tmp/session-first-'.Str::random(16));
        try {
            $login = $this->finish($this->start('login', '-', $base, $org->id));
            self::assertSame(302, $this->finish($this->start('logout', $login['cookie'], $base, $org->id))['status']);
            $result = $this->finish($this->start('sensitive', $login['cookie'], $base, $org->id));
            self::assertSame(302, $result['status']);
            self::assertStringEndsWith('/login', $result['location']);
            self::assertSame('Owner', $user->fresh()->name);
            self::assertFileDoesNotExist($base.'.locked');
        } finally {
            if (is_file($base.'.logout-started')) {
                unlink($base.'.logout-started');
            }
        }
    }

    public function test_remembered_login_creates_registered_session_and_logout_revokes_recaller(): void
    {
        [$org] = $this->createActor();
        $base = base_path('.local/tmp/session-remember-'.Str::random(16));
        try {
            $login = $this->finish($this->start('login-remember', '-', $base, $org->id));
            self::assertIsString($login['remember']);
            $remembered = $this->finish($this->start('remember', $login['remember'], $base, $org->id));
            self::assertSame(200, $remembered['status']);
            self::assertIsString($remembered['cookie']);
            self::assertNotSame($login['session_id'], $remembered['session_id']);
            self::assertSame(302, $this->finish($this->start('logout', $login['cookie'], $base, $org->id))['status']);
            $reuse = $this->finish($this->start('remember', $login['remember'], $base, $org->id));
            self::assertSame(302, $reuse['status']);
            self::assertStringEndsWith('/login', $reuse['location']);
            $oldRememberedSession = $this->finish($this->start('view', $remembered['cookie'], $base, $org->id));
            self::assertSame(302, $oldRememberedSession['status']);
            self::assertStringEndsWith('/login', $oldRememberedSession['location']);
        } finally {
            if (is_file($base.'.logout-started')) {
                unlink($base.'.logout-started');
            }
        }
    }

    public function test_logout_as_first_request_with_only_a_remember_cookie_revokes_it(): void
    {
        [$org] = $this->createActor();
        $base = base_path('.local/tmp/session-remember-logout-'.Str::random(16));
        $login = $this->finish($this->start('login-remember', '-', $base, $org->id));
        self::assertIsString($login['remember']);
        $logout = $this->finish($this->start('logout-remember', $login['remember'], $base, $org->id));
        self::assertSame(302, $logout['status']);
        self::assertStringEndsWith('/login', $logout['location']);
        $reuse = $this->finish($this->start('remember', $login['remember'], $base, $org->id));
        self::assertSame(302, $reuse['status']);
        self::assertStringEndsWith('/login', $reuse['location']);
    }

    public function test_logout_does_not_revoke_another_users_session(): void
    {
        [$org] = $this->createActor();
        $second = User::create(['name' => 'Second', 'email' => 'second@example.test', 'password' => 'race-password-strong']);
        $second->forceFill(['email_verified_at' => now()])->save();
        app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $second->id), fn () => Membership::create(['user_id' => $second->id, 'role' => 'member']));
        $base = base_path('.local/tmp/session-isolation-'.Str::random(16));
        try {
            $first = $this->finish($this->start('login', '-', $base, $org->id));
            $other = $this->finish($this->start('login-second', '-', $base, $org->id));
            self::assertSame(200, $this->finish($this->start('view', $other['cookie'], $base, $org->id))['status']);
            self::assertSame(302, $this->finish($this->start('logout', $first['cookie'], $base, $org->id))['status']);
            self::assertSame(200, $this->finish($this->start('view', $other['cookie'], $base, $org->id))['status']);
        } finally {
            if (is_file($base.'.logout-started')) {
                unlink($base.'.logout-started');
            }
        }
    }

    public function test_logout_preserves_another_normal_session_of_same_user(): void
    {
        [$org] = $this->createActor();
        $base = base_path('.local/tmp/session-same-user-'.Str::random(16));
        try {
            $first = $this->finish($this->start('login', '-', $base, $org->id));
            $second = $this->finish($this->start('login', '-', $base, $org->id));
            self::assertNotSame($first['session_id'], $second['session_id']);
            self::assertSame(302, $this->finish($this->start('logout', $first['cookie'], $base, $org->id))['status']);
            self::assertSame(200, $this->finish($this->start('view', $second['cookie'], $base, $org->id))['status']);
            self::assertStringEndsWith('/login', $this->finish($this->start('view', $first['cookie'], $base, $org->id))['location']);
        } finally {
            if (is_file($base.'.logout-started')) {
                unlink($base.'.logout-started');
            }
        }
    }

    public function test_mfa_enable_rejects_stale_parallel_remember_login(): void
    {
        [$org, $user] = $this->createActor();
        $base = base_path('.local/tmp/session-mfa-remember-'.Str::random(16));
        $slow = null;
        try {
            $initial = $this->finish($this->start('login', '-', $base, $org->id));
            $setup = $this->finish($this->start('mfa-setup', $initial['cookie'], $base, $org->id));
            self::assertIsString($setup['setup_secret']);
            file_put_contents($base.'.code', app(Totp::class)->at($setup['setup_secret'], intdiv(now()->timestamp, 30)));
            $slow = $this->start('login-remember-paused', '-', $base, $org->id);
            $this->waitFile($base.'.user-loaded');
            $confirm = $this->finish($this->start('mfa-confirm', $setup['cookie'], $base, $org->id));
            self::assertStringEndsWith('/auth/ready', $confirm['location']);
            self::assertNotNull($user->fresh()->mfa_confirmed_at);
            file_put_contents($base.'.release', '1');
            $stale = $this->finish($slow);
            $slow = null;
            self::assertNull($stale['remember']);
            self::assertNotSame(200, $this->finish($this->start('view', $stale['cookie'], $base, $org->id))['status']);
            $new = $this->finish($this->start('login-remember', '-', $base, $org->id));
            self::assertStringEndsWith('/mfa/challenge', $new['location']);
            self::assertNull($new['remember']);
        } finally {
            file_put_contents($base.'.release', '1');
            if ($slow !== null) {
                $this->finish($slow);
            }
            foreach ([$base.'.release', $base.'.user-loaded', $base.'.code'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function test_mfa_enable_waits_for_remember_token_issue_and_revokes_that_cookie(): void
    {
        [$org, $user] = $this->createActor();
        $base = base_path('.local/tmp/session-mfa-lock-'.Str::random(16));
        $login = null;
        $confirm = null;
        try {
            $initial = $this->finish($this->start('login', '-', $base, $org->id));
            $setup = $this->finish($this->start('mfa-setup', $initial['cookie'], $base, $org->id));
            self::assertIsString($setup['setup_secret']);
            file_put_contents($base.'.code', app(Totp::class)->at($setup['setup_secret'], intdiv(now()->timestamp, 30)));
            $login = $this->start('login-remember-update-paused', '-', $base, $org->id);
            $this->waitFile($base.'.token-update');
            $confirm = $this->start('mfa-confirm', $setup['cookie'], $base, $org->id);
            $this->waitFile($base.'.confirm-user-loaded');
            usleep(750000);
            self::assertTrue(proc_get_status($confirm[0])['running'], 'MFA enable must wait until remember token issuance commits.');
            file_put_contents($base.'.release', '1');
            $issued = $this->finish($login);
            $login = null;
            self::assertIsString($issued['remember']);
            self::assertStringEndsWith('/auth/ready', $this->finish($confirm)['location']);
            $confirm = null;
            self::assertNotNull($user->fresh()->mfa_confirmed_at);
            self::assertStringEndsWith('/login', $this->finish($this->start('remember', $issued['remember'], $base, $org->id))['location']);
            self::assertStringEndsWith('/login', $this->finish($this->start('view', $issued['cookie'], $base, $org->id))['location']);
        } finally {
            file_put_contents($base.'.release', '1');
            if ($login !== null) {
                $this->finish($login);
            }
            if ($confirm !== null) {
                $this->finish($confirm);
            }
            foreach ([$base.'.release', $base.'.token-update', $base.'.confirm-started', $base.'.confirm-user-loaded', $base.'.code'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function test_logout_rotates_token_created_after_guard_loaded_stale_user(): void
    {
        [$org, $user] = $this->createActor();
        $base = base_path('.local/tmp/session-logout-remember-'.Str::random(16));
        $slow = null;
        try {
            $initial = $this->finish($this->start('login', '-', $base, $org->id));
            self::assertEmpty($user->fresh()->getRememberToken());
            $slow = $this->start('logout-paused', $initial['cookie'], $base, $org->id);
            $this->waitFile($base.'.user-loaded');
            $remembered = $this->finish($this->start('login-remember', '-', $base, $org->id));
            self::assertIsString($remembered['remember']);
            $issuedToken = $user->fresh()->getRememberToken();
            self::assertNotEmpty($issuedToken);
            file_put_contents($base.'.release', '1');
            self::assertSame(302, $this->finish($slow)['status']);
            $slow = null;
            self::assertNotSame($issuedToken, $user->fresh()->getRememberToken());
            $reused = $this->finish($this->start('remember', $remembered['remember'], $base, $org->id));
            self::assertStringEndsWith('/login', $reused['location']);
        } finally {
            file_put_contents($base.'.release', '1');
            if ($slow !== null) {
                $this->finish($slow);
            }
            foreach ([$base.'.release', $base.'.user-loaded'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    private function createActor(): array
    {
        $org = Organization::create(['name' => 'Race organization']);
        $user = User::create(['name' => 'Owner', 'email' => 'race-owner@example.test', 'password' => 'race-password-strong']);
        $user->forceFill(['email_verified_at' => now()])->save();
        app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $user->id), fn () => Membership::create(['user_id' => $user->id, 'role' => 'owner']));

        return [$org, $user];
    }

    private function waitFile(string $path): void
    {
        $deadline = microtime(true) + 20;
        while (! is_file($path) && microtime(true) < $deadline) {
            usleep(10000);
        }
        self::assertFileExists($path);
    }

    private function start(string $mode, string $cookie, string $barrier, string $organization): array
    {
        $cmd = [PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/session-http-worker.php', $mode, $cookie, $barrier];
        $env = array_merge(getenv(), ['APP_ENV' => 'testing', 'SESSION_DRIVER' => 'file', 'CABINET_ORGANIZATION_ID' => $organization]);
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        self::assertIsResource($process);

        return [$process, $pipes];
    }

    private function finish(array $worker): array
    {
        [$process, $pipes] = $worker;
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);

        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }
}
