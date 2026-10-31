<?php

namespace Tests\Feature\Runtime;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\IsolatedAuthDatabase;
use Tests\TestCase;

final class QueueStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        IsolatedAuthDatabase::assert();
    }

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'queue.default' => 'database',
            'queue.connections.database.connection' => 'pgsql',
            'queue.connections.database.table' => 'jobs',
            'queue.failed.database' => 'pgsql',
            'queue.failed.driver' => 'database-uuids',
            'queue.failed.table' => 'failed_jobs',
            'runtime.queue_namespace' => 'e007monitor',
            'runtime.queues' => [
                'seller-turns' => 'e007monitor:seller-turns', 'vision' => 'e007monitor:vision',
                'ai' => 'e007monitor:ai', 'notifications' => 'e007monitor:notifications', 'default' => 'e007monitor:default',
            ],
        ]);
        Bus::fake();
        Mail::fake();
        Http::fake();
    }

    public function test_actual_database_queue_returns_only_aggregates_and_changes_no_data(): void
    {
        $allowed = 'e007monitor:notifications';
        $this->job($allowed, null, time() - 1);
        $this->job($allowed, null, time() + 3600);
        $this->job($allowed, time() - 60, time() - 100);
        $this->job('other-instance:notifications', null, time() - 1);
        $this->job('e007monitor:rogue', null, time() - 1);
        $this->failed($allowed, 'database');
        $this->failed($allowed, 'database');
        $this->failed($allowed, 'redis');
        $this->failed('other-instance:notifications', 'database');
        $before = $this->snapshot();
        $queries = [];
        $capture = true;
        DB::listen(function (QueryExecuted $query) use (&$queries, &$capture): void {
            if ($capture) {
                $queries[] = $query->sql;
            }
        });
        self::assertSame(0, Artisan::call('runtime:queue-status'));
        $output = Artisan::output();
        $capture = false;
        self::assertNotEmpty($queries);
        foreach ($queries as $sql) {
            self::assertMatchesRegularExpression('/^\s*select\b/i', $sql);
            self::assertStringNotContainsString('payload', strtolower($sql));
            self::assertStringNotContainsString('exception', strtolower($sql));
        }
        self::assertMatchesRegularExpression('/e007monitor:notifications\s+\|\s+2\s+\|\s+1\s+\|\s+2/u', $output);
        self::assertMatchesRegularExpression('/e007monitor:seller-turns\s+\|\s+0\s+\|\s+0\s+\|\s+0/u', $output);
        foreach (['PRIVATE_JOB_PAYLOAD', 'PRIVATE_EXCEPTION', 'other-instance', 'e007monitor:rogue'] as $private) {
            self::assertStringNotContainsString($private, $output);
        }
        self::assertSame($before, $this->snapshot());
        Bus::assertNothingDispatched();
        Mail::assertNothingOutgoing();
        Http::assertNothingSent();
    }

    public function test_unsupported_or_missing_tables_fail_without_changing_jobs(): void
    {
        $this->job('e007monitor:notifications', null, time() - 1);
        $this->failed('e007monitor:notifications', 'database');
        $before = $this->snapshot();
        $cases = [
            ['queue.default' => 'sync'],
            ['queue.failed.driver' => 'null'],
            ['queue.failed.database' => 'other-connection'],
            ['queue.connections.database.table' => 'missing_jobs_table'],
            ['queue.failed.table' => 'missing_failed_table'],
            ['runtime.queue_namespace' => "PRIVATE_BAD\nNAME"],
            ['runtime.queues.notifications' => 'other-instance:notifications'],
            ['queue.connections.database.table' => 'jobs;PRIVATE_BAD'],
        ];
        foreach ($cases as $case) {
            $keys = array_keys($case);
            $previous = [];
            foreach ($keys as $key) {
                $previous[$key] = config($key);
            }
            config($case);
            self::assertSame(1, Artisan::call('runtime:queue-status'));
            $output = Artisan::output();
            self::assertStringContainsString('недоступ', mb_strtolower($output, 'UTF-8'));
            self::assertStringNotContainsString('PRIVATE_', $output);
            self::assertSame($before, $this->snapshot());
            config($previous);
        }
        Bus::assertNothingDispatched();
        Mail::assertNothingOutgoing();
        Http::assertNothingSent();
    }

    public function test_command_succeeds_in_a_postgresql_read_only_transaction(): void
    {
        config([
            'database.connections.e007_readonly' => config('database.connections.pgsql'),
            'queue.connections.database.connection' => 'e007_readonly',
            'queue.failed.database' => 'e007_readonly',
        ]);
        $connection = DB::connection('e007_readonly');
        $connection->beginTransaction();
        try {
            $connection->statement('SET TRANSACTION READ ONLY');
            self::assertSame('on', $connection->selectOne('SHOW transaction_read_only')->transaction_read_only);
            self::assertSame(0, Artisan::call('runtime:queue-status'));
            self::assertStringContainsString('e007monitor:notifications', Artisan::output());
        } finally {
            $connection->rollBack();
            DB::purge('e007_readonly');
        }
    }

    private function job(string $queue, ?int $reservedAt, int $availableAt): void
    {
        DB::table('jobs')->insert(['queue' => $queue, 'payload' => '{"secret":"PRIVATE_JOB_PAYLOAD"}', 'attempts' => 0,
            'reserved_at' => $reservedAt, 'available_at' => $availableAt, 'created_at' => time()]);
    }

    private function failed(string $queue, string $connection): void
    {
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => $connection, 'queue' => $queue,
            'payload' => '{"secret":"PRIVATE_JOB_PAYLOAD"}', 'exception' => 'PRIVATE_EXCEPTION', 'failed_at' => now()]);
    }

    private function snapshot(): string
    {
        return json_encode([DB::table('jobs')->orderBy('id')->get(), DB::table('failed_jobs')->orderBy('id')->get()], JSON_THROW_ON_ERROR);
    }
}
