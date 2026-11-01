<?php

namespace Tests\Feature\Tenancy\TokenApi;

use App\Models\Brand;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\IsolatedDatabaseMigrations;
use Tests\TestCase;

final class TokenRevocationRaceTest extends TestCase
{
    use IsolatedDatabaseMigrations;

    public function createApplication()
    {
        $app = parent::createApplication();
        $actual = DB::selectOne('select current_database() as name, current_user as role');
        $base = realpath($app->basePath());
        $runtime = realpath($app->basePath('.local/runtime/testing'));
        $storage = realpath($app->storagePath());
        if (! $app->environment('testing') || ! $base || strcasecmp($base, (string) realpath(getcwd())) !== 0
            || ! $runtime || ! $storage || ! str_starts_with(strtolower($storage), strtolower($runtime).DIRECTORY_SEPARATOR)
            || $actual->name !== config('runtime.test_database') || $actual->name === config('runtime.preview_database')
            || ! preg_match('/(?:^|_)test(?:_|$)/', $actual->name)) {
            throw new \RuntimeException('Race test requires the isolated test checkout and database.');
        }
        if (strcasecmp($base, (string) realpath('C:/OSPanel/home/souzpir.local/.worktrees/sp-api-guard')) === 0
            && ($actual->name !== 'souzpir_test_sp_api_guard' || $actual->role !== 'souzpir_sp_api_guard')) {
            throw new \RuntimeException('Race test must use its own isolated database and role.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('tokenable_type');
                $table->ulid('tokenable_id');
                $table->ulid('organization_id');
                $table->ulid('brand_id');
                $table->string('name');
                $table->string('token', 64)->unique();
                $table->string('token_last_four', 4);
                $table->text('abilities')->nullable();
                $table->timestampTz('last_used_at')->nullable();
                $table->timestampTz('expires_at')->nullable();
                $table->timestampsTz();
            });
        }
    }

    public function test_revoked_token_is_rechecked_after_an_inflight_guard_lookup(): void
    {
        [$org, $brand, $id, $token] = $this->fixture();
        $marker = base_path('.local/tmp/api-race-'.Str::random(24));
        $worker = null;
        DB::beginTransaction();
        try {
            DB::table('organizations')->where('id', $org->id)->lockForUpdate()->first();
            DB::table('brands')->where('id', $brand->id)->lockForUpdate()->first();
            DB::table('personal_access_tokens')->where('id', $id)->delete();
            $worker = $this->worker('action', $org->id, $brand->id, $id, $token, $marker);
            $this->awaitOutput($worker, 'guard-ready');
            DB::commit();
            $worker->wait();
            self::assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            self::assertStringContainsString('status:401', $worker->getOutput());
            self::assertStringNotContainsString('action-started', $worker->getOutput());
            self::assertSame('Race fixture', DB::table('brands')->where('id', $brand->id)->value('name'));
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($worker?->isRunning()) {
                $worker->stop();
            }
        }
    }

    public function test_action_finishes_before_concurrent_revocation_and_key_is_then_invalid(): void
    {
        [$org, $brand, $id, $token] = $this->fixture();
        $marker = base_path('.local/tmp/api-race-'.Str::random(24));
        $action = $revoke = null;
        try {
            $action = $this->worker('action', $org->id, $brand->id, $id, $token, $marker);
            $this->awaitOutput($action, 'action-started');
            $revoke = $this->worker('revoke', $org->id, $brand->id, $id, $token, $marker);
            $this->awaitOutput($revoke, 'revoke-ready');
            self::assertSame(1, preg_match('/revoke-ready:(\d+)/', $revoke->getOutput(), $match));
            $deadline = microtime(true) + 10;
            do {
                $waiting = DB::selectOne('select wait_event_type from pg_stat_activity where pid = ?', [(int) $match[1]]);
                if ($waiting?->wait_event_type === 'Lock') {
                    break;
                }
                usleep(10000);
            } while ($revoke->isRunning() && microtime(true) < $deadline);
            self::assertSame('Lock', $waiting?->wait_event_type, 'Revocation must actually be waiting on a PostgreSQL lock.');
            self::assertTrue($revoke->isRunning(), 'Revocation must wait for the active action transaction.');
            self::assertStringNotContainsString('revoked', $revoke->getOutput());
            file_put_contents($marker, 'release');
            $action->wait();
            $revoke->wait();
            self::assertSame(0, $action->getExitCode(), $action->getErrorOutput());
            self::assertSame(0, $revoke->getExitCode(), $revoke->getErrorOutput());
            self::assertStringContainsString('status:200', $action->getOutput());
            self::assertStringContainsString('revoked', $revoke->getOutput());
            self::assertSame('Committed race fixture', DB::table('brands')->where('id', $brand->id)->value('name'));
            $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1'])
                ->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/brand')->assertUnauthorized();
        } finally {
            foreach ([$action, $revoke] as $worker) {
                if ($worker?->isRunning()) {
                    $worker->stop();
                }
            }
            if (is_file($marker)) {
                unlink($marker);
            }
        }
    }

    private function fixture(): array
    {
        $org = Organization::create(['name' => 'Race organization']);
        $brand = app(TenantContextRunner::class)->run(new TenantContext($org->id, source: 'job'),
            fn () => Brand::create(['name' => 'Race fixture', 'theme' => 'neutral']));
        $secret = Str::random(64);
        $id = DB::table('personal_access_tokens')->insertGetId([
            'tokenable_type' => Brand::class, 'tokenable_id' => $brand->id,
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'name' => 'race',
            'token' => hash('sha256', $secret), 'token_last_four' => substr($secret, -4),
            'abilities' => json_encode(['read'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$org, $brand, $id, $id.'|'.$secret];
    }

    private function worker(string $mode, string $org, string $brand, int $id, string $token, string $marker): Process
    {
        $process = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), base_path('tests/Feature/Tenancy/TokenApi/Fixtures/race-worker.php')],
            base_path(), ['APP_ENV' => 'testing', 'API_RACE_MODE' => $mode, 'API_RACE_ORG' => $org,
                'API_RACE_BRAND' => $brand, 'API_RACE_TOKEN_ID' => (string) $id, 'API_RACE_TOKEN' => $token, 'API_RACE_MARKER' => $marker], timeout: 20);
        $process->start();

        return $process;
    }

    private function awaitOutput(Process $process, string $marker): void
    {
        $deadline = microtime(true) + 10;
        while ($process->isRunning() && ! str_contains($process->getOutput(), $marker) && microtime(true) < $deadline) {
            usleep(10000);
        }
        self::assertStringContainsString($marker, $process->getOutput(), $process->getErrorOutput());
    }
}
