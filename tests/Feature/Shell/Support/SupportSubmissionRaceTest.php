<?php

namespace Tests\Feature\Shell\Support;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\IsolatedDatabaseMigrations;
use Tests\TestCase;

final class SupportSubmissionRaceTest extends TestCase
{
    use IsolatedDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        $database = DB::connection()->getDatabaseName();
        if (! app()->environment('testing') || config('database.default') !== 'pgsql'
            || ! preg_match('/(?:^|_)test(?:_|$)/i', $database)
            || $database !== config('runtime.test_database') || $database === config('runtime.preview_database')) {
            throw new \LogicException('Isolated PostgreSQL test database required.');
        }
    }

    public function test_parallel_http_submissions_share_one_ticket_and_one_fake_delivery(): void
    {
        $owner = User::create(['name' => 'Synthetic owner', 'email' => Str::lower(Str::random(16)).'@example.test', 'password' => 'synthetic-test-password']);
        $owner->forceFill(['email_verified_at' => now(), 'terms_accepted_at' => now(), 'ai_consent_at' => now()])->save();
        $organization = Organization::create(['name' => 'Synthetic support race', 'owner_user_id' => $owner->id]);
        app(TenantContextRunner::class)->run(new TenantContext($organization->id, actorId: $owner->id), function () use ($owner): void {
            Membership::create(['user_id' => $owner->id, 'role' => 'owner']);
            Brand::create(['name' => 'Synthetic brand']);
        });

        $directory = base_path('.local/runtime/support-race-'.Str::lower(Str::random(20)));
        mkdir($directory, 0700, true);
        foreach (['sessions', 'storage/framework/views', 'storage/framework/cache', 'storage/logs', 'cache'] as $subdirectory) {
            mkdir($directory.'/'.$subdirectory, 0700, true);
        }
        $processes = [];
        $transaction = false;
        try {
            $login = $this->worker($organization->id, $directory, [
                'mode' => 'login', 'email' => $owner->email, 'password' => 'synthetic-test-password',
            ]);
            $login->run();
            self::assertTrue($login->isSuccessful(), $login->getErrorOutput());
            $loginResult = json_decode($login->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(302, $loginResult['status']);
            self::assertNotEmpty($loginResult['cookie']);

            // A safe request initializes the selected brand in the persisted session.
            $ready = $this->worker($organization->id, $directory, ['mode' => 'ready', 'cookie' => $loginResult['cookie']]);
            $ready->run();
            self::assertTrue($ready->isSuccessful(), $ready->getErrorOutput());
            $readyResult = json_decode($ready->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(200, $readyResult['status']);
            self::assertNotEmpty($readyResult['cookie']);

            $key = (string) Str::ulid();
            DB::beginTransaction();
            $transaction = true;
            $sessions = DB::table('auth_session_registry')->where('user_id', $owner->id)->whereNull('revoked_at')->lockForUpdate()->get();
            self::assertCount(1, $sessions);
            foreach ([1, 2] as $number) {
                $processes[$number] = $this->worker($organization->id, $directory, [
                    'mode' => 'submit', 'cookie' => $readyResult['cookie'], 'key' => $key, 'number' => $number,
                ]);
                $processes[$number]->start();
            }
            $pids = [];
            foreach ($processes as $number => $process) {
                $marker = $directory.'/waiting-'.$number;
                $deadline = microtime(true) + 30;
                while (! is_file($marker) && $process->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                self::assertFileExists($marker, 'Worker did not reach session locking query: '.$process->getErrorOutput());
                $pids[] = (int) file_get_contents($marker);
            }
            self::assertCount(2, array_unique($pids));
            $deadline = microtime(true) + 15;
            do {
                $blocked = (int) DB::table('pg_stat_activity')->whereIn('pid', $pids)->where('wait_event_type', 'Lock')->count();
                if ($blocked === 2) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $blocked, 'Both request processes must wait at the PostgreSQL lock.');
            DB::commit();
            $transaction = false;

            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }
            $statuses = array_column($results, 'status');
            sort($statuses);
            self::assertSame([200, 201], $statuses);
            self::assertNotEmpty($results[0]['ticket']);
            self::assertSame($results[0]['ticket'], $results[1]['ticket']);
            self::assertSame(1, array_sum(array_column($results, 'fake_mail_count')));
            self::assertSame(1, DB::table('tickets')->where('organization_id', $organization->id)->where('submission_key', $key)->count());
            self::assertSame($results[0]['ticket'], DB::table('tickets')->where('organization_id', $organization->id)->where('submission_key', $key)->value('id'));
        } finally {
            if ($transaction) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(2);
                }
            }
            $this->removeFixture($directory);
        }
    }

    private function worker(string $organization, string $directory, array $input): Process
    {
        $connection = config('database.connections.pgsql');
        $environment = [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'CABINET_ORGANIZATION_ID' => $organization,
            'DB_URL' => '', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => DB::connection()->getDatabaseName(), 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'] ?? '',
            'DB_TEST_DATABASE' => config('runtime.test_database'), 'APP_PREVIEW_DATABASE' => config('runtime.preview_database') ?? '',
            'SESSION_DRIVER' => 'file', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'APP_STORAGE_PATH' => $directory.'/storage', 'APP_CONFIG_CACHE' => $directory.'/cache/config.php',
            'APP_ROUTES_CACHE' => $directory.'/cache/routes.php', 'APP_EVENTS_CACHE' => $directory.'/cache/events.php',
            'APP_SERVICES_CACHE' => $directory.'/cache/services.php', 'APP_PACKAGES_CACHE' => $directory.'/cache/packages.php',
            'VIEW_COMPILED_PATH' => $directory.'/storage/framework/views',
        ];

        return new Process([PHP_BINARY, '-d', 'sys_temp_dir='.$directory, __DIR__.'/Fixtures/submission-race-worker.php'], base_path(), $environment,
            json_encode($input + ['directory' => $directory, 'database' => DB::connection()->getDatabaseName()], JSON_THROW_ON_ERROR), 60);
    }

    private function removeFixture(string $directory): void
    {
        if (! is_dir($directory) || is_link($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
