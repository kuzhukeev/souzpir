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

final class SiteProgressE821ETest extends TenancyTestCase
{
    public function test_job_shows_only_its_brand_progress_and_keeps_results_after_completion(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        config(['features.E8-01B.defaults.fetch_enabled' => true]);
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/vision/site/audit', ['url' => 'https://example.test'])->assertRedirect()->assertSessionHasNoErrors();
        $job = Queue::pushed(AuditSiteJob::class)->first();
        $key = AuditSiteJob::key($org->id, $brand->id);
        $foreignKey = AuditSiteJob::key($org->id, $other->id);
        $inspector = \Mockery::mock(SiteInspector::class);
        $inspector->shouldReceive('inspect')->once()->andReturnUsing(function ($url, $limit, $callback) use ($key, $foreignKey): array {
            self::assertSame(50, $limit);
            $callback(1, 3);
            self::assertSame(1, Cache::get($key)['pages_processed']);
            self::assertNull(Cache::get($foreignKey));
            $callback(2, 3);
            self::assertSame(3, Cache::get($key)['pages_total']);
            self::assertIsInt(Cache::get($key)['remaining_seconds']);
            self::assertGreaterThanOrEqual(0, Cache::get($key)['remaining_seconds']);
            $callback(3, 3);
            self::assertSame('analyzing', Cache::get($key)['stage']);
            self::assertSame(0, Cache::get($key)['remaining_seconds']);

            return ['url' => $url, 'pages' => []];
        });
        $job->handle($inspector, app(SiteAccess::class), app(AuditWriter::class));
        self::assertSame('complete', Cache::get($key)['stage']);
        self::assertIsInt(Cache::get($key)['elapsed_seconds']);
        self::assertSame(3, Cache::get($key)['pages_processed']);
        self::assertSame(0, Cache::get($key)['remaining_seconds']);
        Http::assertNothingSent();
    }
}
