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

final class AuditQueueE812CTest extends TenancyTestCase
{
    public function test_audit_is_queued_per_brand_and_rejects_unsafe_urls(): void
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
        Queue::assertPushed(AuditSiteJob::class, fn ($job) => $job->brandId === $brand->id && $job->url === 'https://example.test');
        $this->get('/vision/site')->assertOk()->assertInertia(fn ($page) => $page->where('auditProgress.stage', 'queued'));
        $this->post('/vision/site/audit', ['url' => 'https://example.test/?private=1'])->assertSessionHasErrors('url');
        Queue::assertPushed(AuditSiteJob::class, 1);
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/vision/site')->assertOk()->assertInertia(fn ($page) => $page->where('auditProgress', null));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/vision/site/audit', ['url' => 'https://example.test'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_revoked_session_before_worker_stops_fetch_and_save(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        config(['features.E8-01B.defaults.fetch_enabled' => true]);
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/vision/site/audit', ['url' => 'https://example.test'])->assertRedirect()->assertSessionHasNoErrors();
        $job = Queue::pushed(AuditSiteJob::class)->first();
        self::assertNotNull($job);
        self::assertStringNotContainsString(session()->getId(), serialize($job));
        $firstHash = DB::table('auth_session_registry')->where('user_id', $owner->id)->value('session_hash');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        DB::table('auth_session_registry')->where('session_hash', $firstHash)->update(['revoked_at' => now()]);
        self::assertSame(1, DB::table('auth_session_registry')->where('user_id', $owner->id)->whereNull('revoked_at')->count());
        $inspector = \Mockery::mock(SiteInspector::class);
        $inspector->shouldNotReceive('inspect');
        $job->handle($inspector, app(SiteAccess::class), app(AuditWriter::class));
        self::assertSame('failed', Cache::get(AuditSiteJob::key($org->id, $brand->id))['stage']);
        $this->assertDatabaseCount('audit_runs', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'vision.site.audit.created']);
        Http::assertNothingSent();
    }

    public function test_job_records_failure_without_creating_an_audit(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $inspector = \Mockery::mock(SiteInspector::class);
        $inspector->shouldReceive('inspect')->once()->andThrow(new \RuntimeException('network failed'));
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $hash = DB::table('auth_session_registry')->where('user_id', $owner->id)->value('session_hash');
        $job = new AuditSiteJob($org->id, $brand->id, $owner->id, $membership->id, 'https://example.test', $hash, $owner->auth_version);
        $job->handle($inspector, app(SiteAccess::class), app(AuditWriter::class));
        self::assertSame('failed', Cache::get(AuditSiteJob::key($org->id, $brand->id))['stage']);
        $this->assertDatabaseCount('audit_runs', 0);
        Http::assertNothingSent();
    }
}
