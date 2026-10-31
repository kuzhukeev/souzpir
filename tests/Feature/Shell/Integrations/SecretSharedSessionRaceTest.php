<?php

namespace Tests\Feature\Shell\Integrations;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class SecretSharedSessionRaceTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_processes_with_shared_database_session_deliver_secret_only_once(): void
    {
        Http::preventStrayRequests();
        $owner = User::create(['name' => 'Race owner', 'email' => 'e110-race@example.test', 'password' => 'race-password-strong']);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $organization = Organization::create(['name' => 'Race fixture', 'owner_user_id' => $owner->id]);
        app(TenantContextRunner::class)->run(new TenantContext($organization->id, actorId: $owner->id), function () use ($owner): void {
            Membership::create(['user_id' => $owner->id, 'role' => 'owner']);
            Brand::create(['name' => 'Race brand', 'theme' => 'neutral']);
        });
        $barrier = base_path('.local/tmp/e110-race-'.Str::random(16));
        $first = null;
        $second = null;
        try {
            $login = $this->worker('login', null, $barrier, $organization->id);
            $login->mustRun();
            $loginResult = json_decode($login->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(302, $loginResult['status']);
            self::assertIsString($loginResult['cookie']);
            $select = $this->worker('select', $loginResult['cookie'], $barrier, $organization->id);
            $select->mustRun();
            $selected = json_decode($select->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(204, $selected['status']);
            $outer = $this->worker('outer', $selected['cookie'], $barrier, $organization->id);
            $outer->mustRun();
            self::assertSame(503, json_decode($outer->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status']);
            self::assertSame(0, DB::table('personal_access_tokens')->count());
            $create = $this->worker('create', $selected['cookie'], $barrier, $organization->id);
            $create->mustRun();
            $created = json_decode($create->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(302, $created['status']);
            $first = $this->worker('first', $created['cookie'], $barrier, $organization->id);
            $first->start();
            $this->waitFor($barrier.'.locked');
            $second = $this->worker('second', $created['cookie'], $barrier, $organization->id);
            $second->start();
            $this->waitFor($barrier.'.second-started');
            usleep(250000);
            self::assertTrue($second->isRunning(), 'The second request must wait for the first session lock.');
            file_put_contents($barrier.'.release', '1');
            self::assertSame(0, $first->wait(), $first->getErrorOutput());
            self::assertSame(0, $second->wait(), $second->getErrorOutput());
            $one = json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $two = json_decode($second->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(200, $one['status']);
            self::assertTrue($one['has_value']);
            self::assertSame(404, $two['status']);
            self::assertFalse($two['has_value']);
        } finally {
            file_put_contents($barrier.'.release', '1');
            foreach ([$first, $second] as $worker) {
                if ($worker && $worker->isRunning()) {
                    $worker->wait();
                }
            }
            foreach (['.locked', '.second-started', '.release'] as $suffix) {
                if (is_file($barrier.$suffix)) {
                    unlink($barrier.$suffix);
                }
            }
        }
    }

    private function worker(string $mode, ?string $cookie, string $barrier, string $organization): Process
    {
        return new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/secret-session-worker.php'], base_path(), [
            'APP_ENV' => 'testing', 'SESSION_DRIVER' => 'database', 'CACHE_STORE' => 'database', 'CABINET_ORGANIZATION_ID' => $organization,
        ], json_encode(compact('mode', 'cookie', 'barrier'), JSON_THROW_ON_ERROR), 25);
    }

    private function waitFor(string $file): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($file) && microtime(true) < $deadline) {
            usleep(10000);
        }
        self::assertFileExists($file, 'Concurrent request did not reach the expected barrier.');
    }
}
