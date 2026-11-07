<?php

namespace Tests\Feature\Vision\Site;

use App\Shell\Audit\Contracts\AuditWriter;
use App\Vision\Site\Jobs\AuditSiteJob;
use App\Vision\Site\SiteAccess;
use App\Vision\SiteAudit\SiteInspector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SiteTimingE821QTest extends TenancyTestCase
{
    public function test_fake_failure_keeps_elapsed_time_and_never_exposes_an_estimate(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        config(['features.E8-01B.defaults.fetch_enabled' => true]);
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/vision/site/audit', ['url' => 'https://example.test'])->assertRedirect()->assertSessionHasNoErrors();
        $job = Queue::pushed(AuditSiteJob::class)->first();
        self::assertSame(75, $job->timeout);
        self::assertSame(1, $job->tries);
        self::assertGreaterThan($job->timeout, config('queue.connections.database.retry_after'));
        $key = AuditSiteJob::key($org->id, $brand->id);
        $inspector = \Mockery::mock(SiteInspector::class);
        $inspector->shouldReceive('inspect')->once()->andReturnUsing(function ($url, $limit, $callback) use ($key): never {
            $callback(2, 3);
            self::assertNull(Cache::get($key)['remaining_seconds']);
            $this->get('/vision/site')->assertOk()->assertInertia(fn ($page) => $page->where('auditProgress.remaining_seconds', null));
            throw new \RuntimeException('Fake failure');
        });
        $job->handle($inspector, app(SiteAccess::class), app(AuditWriter::class));
        self::assertSame('failed', Cache::get($key)['stage']);
        self::assertIsInt(Cache::get($key)['elapsed_seconds']);
        self::assertGreaterThanOrEqual(0, Cache::get($key)['elapsed_seconds']);
        Http::assertNothingSent();
    }
}
