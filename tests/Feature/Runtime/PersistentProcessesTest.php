<?php

namespace Tests\Feature\Runtime;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\Feature\Runtime\Fixtures\PersistentProbeJob;
use Tests\TestCase;

final class PersistentProcessesTest extends TestCase
{
    protected function setUp(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('Persistent processes are tested only on Linux CI.');
        }
        parent::setUp();
    }

    public function test_persistent_worker_processes_a_synthetic_job_and_stops(): void
    {
        $suffix = bin2hex(random_bytes(5));
        $table = 'runtime_jobs_'.$suffix;
        $queue = 'runtime-test-'.$suffix;
        $marker = base_path('.local/runtime/testing/probe-'.$suffix);
        Schema::connection('pgsql')->create($table, function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        $process = null;
        try {
            config(['queue.connections.database.connection' => 'pgsql', 'queue.connections.database.table' => $table]);
            Queue::connection('database')->pushOn($queue, new PersistentProbeJob($marker));
            $process = $this->process(['queue:work', 'database', '--queue='.$queue, '--sleep=1', '--tries=1'], $table);
            $process->start();
            $deadline = microtime(true) + 25;
            while (! is_file($marker) && $process->isRunning() && microtime(true) < $deadline) {
                usleep(100000);
            }
            self::assertFileExists($marker, $process->getOutput().$process->getErrorOutput());
            self::assertSame('completed', file_get_contents($marker));
            self::assertTrue($process->isRunning(), 'Worker must remain running after the job.');
            while (DB::connection('pgsql')->table($table)->count() !== 0 && $process->isRunning() && microtime(true) < $deadline) {
                usleep(100000);
            }
            self::assertSame(0, DB::connection('pgsql')->table($table)->count());
            $process->stop(10, SIGTERM);
            self::assertFalse($process->isRunning());
        } finally {
            if ($process?->isRunning()) {
                $process->stop(3, SIGTERM);
            }
            Schema::connection('pgsql')->dropIfExists($table);
            if (is_file($marker)) {
                unlink($marker);
            }
        }
    }

    public function test_persistent_scheduler_runs_an_empty_test_schedule_and_stops(): void
    {
        $process = $this->process(['schedule:work'], 'unused_runtime_jobs');
        try {
            $process->start();
            $deadline = microtime(true) + 75;
            while ($process->isRunning() && microtime(true) < $deadline
                && ! str_contains($process->getOutput(), 'No scheduled commands are ready to run')) {
                usleep(200000);
            }
            self::assertStringContainsString('No scheduled commands are ready to run', $process->getOutput(), $process->getErrorOutput());
            self::assertTrue($process->isRunning());
            $process->stop(10, SIGTERM);
            self::assertFalse($process->isRunning());
        } finally {
            if ($process->isRunning()) {
                $process->stop(3, SIGTERM);
            }
        }
    }

    private function process(array $arguments, string $table): Process
    {
        $db = config('database.connections.pgsql');

        return new Process(array_merge([PHP_BINARY, 'artisan'], $arguments, ['--env=testing', '--no-interaction']), base_path(), [
            'APP_ENV' => 'testing', 'DB_URL' => '', 'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => $db['database'],
            'DB_TEST_DATABASE' => $db['database'], 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'],
            'DB_QUEUE_CONNECTION' => 'pgsql', 'DB_QUEUE_TABLE' => $table, 'QUEUE_CONNECTION' => 'database',
            'FEATURES_SCHEDULING_ENABLED' => 'false', 'MAIL_MAILER' => 'log', 'AI_DRIVER' => 'fake',
            'DELIVERY_DRIVER' => 'fake', 'NOTIFICATIONS_EXTERNAL_ENABLED' => 'false',
        ], null, 90);
    }
}
