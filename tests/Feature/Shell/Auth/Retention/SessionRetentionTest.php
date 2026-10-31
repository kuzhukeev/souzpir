<?php

namespace Tests\Feature\Shell\Auth\Retention;

use App\Models\User;
use App\Shell\Auth\Retention\SessionRetention;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SessionRetentionTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        $expected = basename(dirname(base_path())) === '.worktrees' ? 'souzpir_test_'.str_replace('-', '_', basename(base_path())) : 'souzpir_test_main';
        if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== $expected
            || DB::connection()->getDatabaseName() !== config('runtime.test_database')
            || DB::connection()->getDatabaseName() === config('runtime.preview_database')) {
            throw new \LogicException('Isolated test database required.');
        }
    }

    private function insertSession(User $user, string $hash, string $expires, ?string $revoked = null): void
    {
        DB::table('auth_session_registry')->insert([
            'session_hash' => $hash, 'user_id' => $user->id, 'auth_version' => 0,
            'expires_at' => $expires, 'revoked_at' => $revoked, 'created_at' => now()->subDays(200), 'updated_at' => now()->subDays(200),
        ]);
    }

    public function test_prunes_only_old_records_in_bounded_idempotent_batches(): void
    {
        $user = User::create(['name' => 'Test', 'email' => 'retention@example.test', 'password' => 'temporary-password']);
        $this->insertSession($user, str_repeat('a', 64), now()->subDays(100)->toISOString());
        $this->insertSession($user, str_repeat('b', 64), now()->addDay()->toISOString(), now()->subDays(100)->toISOString());
        $this->insertSession($user, str_repeat('c', 64), now()->addDay()->toISOString());
        $this->insertSession($user, str_repeat('d', 64), now()->subDays(100)->toISOString(), now()->subDay()->toISOString());
        $this->insertSession($user, str_repeat('e', 64), now()->subDays(89)->toISOString());
        $retention = app(SessionRetention::class);
        self::assertSame(1, $retention->prune(90, 1));
        self::assertSame(1, $retention->prune(90, 1));
        self::assertSame(0, $retention->prune(90, 1));
        self::assertEqualsCanonicalizing([str_repeat('c', 64), str_repeat('d', 64), str_repeat('e', 64)], DB::table('auth_session_registry')->pluck('session_hash')->all());
        self::assertTrue(User::whereKey($user->id)->exists());
    }

    public function test_locked_session_is_skipped_then_removed_after_release(): void
    {
        $user = User::create(['name' => 'Test', 'email' => 'locked@example.test', 'password' => 'temporary-password']);
        $hash = str_repeat('a', 64);
        $this->insertSession($user, $hash, now()->subDays(100)->toISOString());
        config(['database.connections.retention_lock' => config('database.connections.pgsql')]);
        $lock = DB::connection('retention_lock');
        $lock->beginTransaction();
        try {
            $lock->table('auth_session_registry')->where('session_hash', $hash)->lockForUpdate()->first();
            self::assertSame(0, app(SessionRetention::class)->prune(90, 10));
            self::assertTrue(DB::table('auth_session_registry')->where('session_hash', $hash)->exists());
        } finally {
            $lock->rollBack();
            DB::purge('retention_lock');
        }
        self::assertSame(1, app(SessionRetention::class)->prune(90, 10));
    }

    public function test_another_process_cannot_have_its_locked_session_deleted(): void
    {
        $user = User::create(['name' => 'Test', 'email' => 'process@example.test', 'password' => 'temporary-password']);
        $hash = str_repeat('a', 64);
        $this->insertSession($user, $hash, now()->subDays(100)->toISOString());
        $command = [PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/session-lock-worker.php'];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        self::assertIsResource($process);
        stream_set_timeout($pipes[1], 10);
        try {
            fwrite($pipes[0], $hash."\n");
            fflush($pipes[0]);
            self::assertSame("locked\n", fgets($pipes[1]));
            self::assertSame(0, app(SessionRetention::class)->prune(90, 10));
            self::assertTrue(DB::table('auth_session_registry')->where('session_hash', $hash)->exists());
            fwrite($pipes[0], "continue\n");
            fflush($pipes[0]);
            self::assertSame("released\n", fgets($pipes[1]));
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }
        self::assertSame(1, app(SessionRetention::class)->prune(90, 10));
    }

    public function test_command_validates_configuration_and_does_not_print_session_hash(): void
    {
        $user = User::create(['name' => 'Test', 'email' => 'console@example.test', 'password' => 'temporary-password']);
        $hash = str_repeat('f', 64);
        $this->insertSession($user, $hash, now()->subDays(100)->toISOString());
        config(['features.E1-01C.defaults.retention_days' => 0]);
        $this->artisan('auth:prune-session-registry')->assertFailed()->expectsOutput('Invalid session retention settings.');
        self::assertTrue(DB::table('auth_session_registry')->where('session_hash', $hash)->exists());
        config(['features.E1-01C.defaults.retention_days' => 90]);
        $this->artisan('auth:prune-session-registry', ['--limit' => 1])->expectsOutput('Expired or revoked sessions removed: 1')->assertSuccessful();
    }
}
