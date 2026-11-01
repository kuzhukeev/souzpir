<?php

namespace Tests\Feature\Shell\Integrations;

use App\Shell\Integrations\PruneSecretReceipts;
use App\Shell\Integrations\SecretReceiptsCleanupSchedule;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\IsolatedDatabaseMigrations;
use Tests\TestCase;

final class PruneSecretReceiptsTest extends TestCase
{
    use IsolatedDatabaseMigrations;

    public function createApplication()
    {
        $app = parent::createApplication();
        $base = realpath($app->basePath());
        $state = DB::selectOne('select current_database() as name, inet_server_addr() as host, inet_server_port() as port, rolsuper, rolcreatedb, rolcreaterole from pg_roles where rolname = current_user');
        $testDatabase = config('runtime.test_database');
        if (! $app->environment('testing') || ! $base || strcasecmp($base, (string) realpath(getcwd())) !== 0
            || ! is_string($testDatabase) || ! preg_match('/(?:^|_)test(?:_|$)/', $testDatabase)
            || $state->name !== $testDatabase || $state->name === config('runtime.preview_database')
            || (! str_starts_with((string) $state->host, '127.') && $state->host !== '::1')
            || (int) $state->port !== (int) config('database.connections.pgsql.port')
            || $state->rolsuper || $state->rolcreatedb || $state->rolcreaterole) {
            throw new RuntimeException('Receipt cleanup tests require an isolated database.');
        }

        return $app;
    }

    public function test_large_backlog_advances_in_bounded_batches_and_preserves_live_receipts(): void
    {
        $expired = [];
        $expiry = now()->subHour();
        for ($i = 0; $i < 2048; $i++) {
            $expired[] = ['nonce_hash' => hash('sha256', 'expired-'.$i), 'expires_at' => $expiry, 'consumed_at' => null];
        }
        foreach (array_chunk($expired, 250) as $chunk) {
            DB::table('integration_secret_receipts')->insert($chunk);
        }
        $liveHash = hash('sha256', 'unexpired');
        DB::table('integration_secret_receipts')->insert(['nonce_hash' => $liveHash, 'expires_at' => now()->addHour(), 'consumed_at' => null]);
        $pruner = app(PruneSecretReceipts::class);
        foreach ([500, 500, 500, 500, 48, 0] as $expected) {
            self::assertSame($expected, $pruner->prune(500));
        }
        self::assertSame(1, DB::table('integration_secret_receipts')->count());
        $this->assertDatabaseHas('integration_secret_receipts', ['nonce_hash' => $liveHash]);
        $index = DB::selectOne("SELECT indexdef FROM pg_indexes WHERE schemaname = current_schema() AND indexname = 'e110_receipts_expiry_nonce_idx'");
        self::assertNotNull($index);
        self::assertStringContainsString('(expires_at, nonce_hash)', $index->indexdef);
    }

    public function test_expiry_boundary_and_consumed_receipts_are_handled_without_touching_live_rows(): void
    {
        $instant = now()->startOfSecond();
        $this->travelTo($instant);
        $due = hash('sha256', 'exact-cutoff');
        $later = hash('sha256', 'later');
        DB::table('integration_secret_receipts')->insert([
            ['nonce_hash' => $due, 'expires_at' => $instant, 'consumed_at' => $instant->copy()->subMinute()],
            ['nonce_hash' => $later, 'expires_at' => $instant->copy()->addSecond(), 'consumed_at' => null],
        ]);
        self::assertSame(1, app(PruneSecretReceipts::class)->prune(100));
        $this->assertDatabaseMissing('integration_secret_receipts', ['nonce_hash' => $due]);
        $this->assertDatabaseHas('integration_secret_receipts', ['nonce_hash' => $later]);
        $this->travelBack();
    }

    public function test_failure_rolls_back_and_a_later_run_retries_without_exposing_errors(): void
    {
        $this->insertExpired(2);
        $fail = true;
        DB::listen(static function (QueryExecuted $query) use (&$fail): void {
            if ($fail && str_starts_with(strtolower($query->sql), 'delete') && str_contains($query->sql, 'integration_secret_receipts')) {
                throw new RuntimeException('synthetic-sensitive-failure');
            }
        });
        $this->artisan('integrations:prune-secret-receipts', ['--limit' => 1])
            ->expectsOutput('Receipt cleanup failed.')->assertExitCode(1);
        self::assertSame(2, DB::table('integration_secret_receipts')->count());
        $fail = false;
        self::assertSame(1, app(PruneSecretReceipts::class)->prune(1));
        self::assertSame(1, app(PruneSecretReceipts::class)->prune(1));
        self::assertSame(0, app(PruneSecretReceipts::class)->prune(1));
    }

    public function test_invalid_limits_and_outer_transactions_fail_closed(): void
    {
        $this->insertExpired(1);
        $this->artisan('integrations:prune-secret-receipts', ['--limit' => '0'])->assertExitCode(1);
        $this->artisan('integrations:prune-secret-receipts', ['--limit' => '1001'])->assertExitCode(1);
        DB::transaction(function (): void {
            try {
                app(PruneSecretReceipts::class)->prune(1);
                self::fail('Cleanup accepted an outer transaction.');
            } catch (LogicException) {
                self::assertSame(1, DB::table('integration_secret_receipts')->count());
            }
        });
    }

    public function test_schedule_registers_one_bounded_cleanup_command(): void
    {
        $schedule = new Schedule('UTC');
        app(SecretReceiptsCleanupSchedule::class)->register($schedule);
        self::assertCount(1, $schedule->events());
        $event = $schedule->events()[0];
        self::assertStringContainsString('integrations:prune-secret-receipts', $event->command);
        self::assertSame('*/5 * * * *', $event->expression);
        self::assertTrue($event->withoutOverlapping);
        self::assertSame(10, $event->expiresAt);
    }

    #[Group('race')]
    public function test_locked_receipts_are_skipped_and_cleaned_after_unlock(): void
    {
        $keys = $this->insertExpired(4);
        $live = hash('sha256', 'locked-test-live');
        DB::table('integration_secret_receipts')->insert(['nonce_hash' => $live, 'expires_at' => now()->addHour()]);
        $this->withWorker('lock', $keys[0], function (): void {
            self::assertSame(2, app(PruneSecretReceipts::class)->prune(2));
            self::assertSame(1, app(PruneSecretReceipts::class)->prune(2));
        });
        self::assertSame(1, app(PruneSecretReceipts::class)->prune(2));
        $this->assertDatabaseHas('integration_secret_receipts', ['nonce_hash' => $live]);
    }

    #[Group('race')]
    public function test_concurrent_pruners_do_not_delete_the_same_batch(): void
    {
        $this->insertExpired(4);
        $this->withWorker('prune', null, function (): void {
            self::assertSame(2, app(PruneSecretReceipts::class)->prune(2));
        }, '2');
        self::assertSame(0, DB::table('integration_secret_receipts')->count());
    }

    private function insertExpired(int $count): array
    {
        $hashes = [];
        for ($i = 0; $i < $count; $i++) {
            $hashes[] = $hash = hash('sha256', 'receipt-'.$i);
            DB::table('integration_secret_receipts')->insert(['nonce_hash' => $hash, 'expires_at' => now()->subHour()]);
        }

        return $hashes;
    }

    private function withWorker(string $mode, ?string $hash, callable $during, string $expected = 'locked-released'): void
    {
        $barrier = base_path('.local/tmp/e110-prune-'.Str::random(16));
        $process = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/receipt-prune-worker.php'], base_path(),
            ['APP_ENV' => 'testing'], json_encode(compact('mode', 'hash', 'barrier'), JSON_THROW_ON_ERROR), 25);
        try {
            $process->start();
            $deadline = microtime(true) + 15;
            while (! is_file($barrier.'.locked') && $process->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            self::assertFileExists($barrier.'.locked', $process->getErrorOutput());
            $during();
            file_put_contents($barrier.'.release', '1');
            self::assertSame(0, $process->wait(), $process->getErrorOutput());
            self::assertSame($expected, $process->getOutput());
        } finally {
            file_put_contents($barrier.'.release', '1');
            if ($process->isRunning()) {
                $process->wait();
            }
            foreach (['.locked', '.release'] as $suffix) {
                if (is_file($barrier.$suffix)) {
                    unlink($barrier.$suffix);
                }
            }
        }
    }
}
