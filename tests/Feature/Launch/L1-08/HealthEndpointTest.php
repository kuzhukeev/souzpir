<?php

namespace Tests\Feature\Launch\L1_08;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\IsolatedAuthDatabase;
use Tests\TestCase;

final class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        IsolatedAuthDatabase::assert();
    }

    public function test_health_is_ok_with_empty_queue_and_no_failures(): void
    {
        $this->get('/health')->assertOk()->assertJson([
            'status' => 'ok', 'database' => true,
            'queue' => ['healthy' => true, 'lag_seconds' => 0, 'failed_jobs' => 0],
        ]);
    }

    public function test_health_degrades_when_oldest_job_exceeds_lag_threshold(): void
    {
        config(['features.L1-08.defaults.queue_lag_warn_seconds' => 10]);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0,
            'available_at' => now()->timestamp, 'created_at' => now()->timestamp - 60]);

        $response = $this->get('/health');

        $response->assertStatus(503)->assertJsonPath('status', 'degraded')
            ->assertJsonPath('queue.healthy', false);
    }

    public function test_health_degrades_when_too_many_failed_jobs(): void
    {
        config(['features.L1-08.defaults.failed_jobs_warn_count' => 0]);
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(),
            'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()]);

        $this->get('/health')->assertStatus(503)->assertJsonPath('queue.healthy', false);
    }

    public function test_health_does_not_leak_secrets(): void
    {
        $response = $this->get('/health');

        $response->assertOk();
        self::assertStringNotContainsString('DB_PASSWORD', $response->getContent());
        self::assertArrayNotHasKey('config', $response->json());
    }
}
