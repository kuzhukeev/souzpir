<?php

namespace Tests\Feature\Tenancy\TokenApi;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Feature\Shell\Support\Fixtures\IsolatedPlanTestDatabase;
use Tests\Support\IsolatedDatabaseMigrations;
use Tests\TestCase;

final class IngressRateRaceTest extends TestCase
{
    use IsolatedDatabaseMigrations;

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $app->make(Kernel::class)->bootstrap();
        IsolatedPlanTestDatabase::assert($app);

        return $app;
    }

    public function test_two_http_workers_share_one_atomic_peer_limit(): void
    {
        $directory = base_path('.local/runtime/testing/ingress-race-'.Str::lower(Str::random(20)));
        mkdir($directory, 0700, true);
        // Both workers bootstrap at once; create every runtime directory before
        // starting them so the fixture does not race in bootstrap's mkdir calls.
        foreach (['cache', 'storage/app/private', 'storage/app/public', 'storage/framework/cache/data',
            'storage/framework/cache/locks', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $path) {
            mkdir($directory.'/'.$path, 0700, true);
        }
        $connection = DB::connection()->getConfig();
        $env = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_URL' => '', 'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => DB::connection()->getDatabaseName(),
            'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'] ?? '', 'DB_TEST_DATABASE' => config('runtime.test_database'),
            'APP_PREVIEW_DATABASE' => config('runtime.preview_database'), 'CACHE_STORE' => 'file', 'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync', 'AI_DRIVER' => 'fake', 'DELIVERY_DRIVER' => 'fake',
            'APP_STORAGE_PATH' => $directory.'/storage', 'APP_CONFIG_CACHE' => $directory.'/cache/config.php',
            'APP_ROUTES_CACHE' => $directory.'/cache/routes.php', 'APP_EVENTS_CACHE' => $directory.'/cache/events.php',
            'APP_SERVICES_CACHE' => $directory.'/cache/services.php', 'APP_PACKAGES_CACHE' => $directory.'/cache/packages.php'];
        $workers = [];
        try {
            foreach ([1, 2] as $number) {
                $workers[$number] = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/ingress-rate-worker.php'], base_path(), $env,
                    json_encode(['directory' => $directory, 'number' => $number], JSON_THROW_ON_ERROR), 60);
                $workers[$number]->start();
            }
            foreach ($workers as $number => $worker) {
                $deadline = microtime(true) + 25;
                while (! is_file($directory.'/ready-'.$number) && $worker->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                self::assertFileExists($directory.'/ready-'.$number, 'Worker did not start: '.$worker->getErrorOutput());
            }
            file_put_contents($directory.'/start', 'go', LOCK_EX);
            $totals = ['401' => 0, '429' => 0, 'other' => 0];
            foreach ($workers as $worker) {
                $worker->wait();
                self::assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $output = $worker->getOutput();
                self::assertJson($output, 'Unexpected worker output: '.$output.'; stderr: '.$worker->getErrorOutput());
                $counts = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
                foreach ($totals as $status => $count) {
                    $totals[$status] += $counts[$status];
                }
            }
            self::assertSame(['401' => 60, '429' => 20, 'other' => 0], $totals);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(2);
                }
            }
        }
    }
}
