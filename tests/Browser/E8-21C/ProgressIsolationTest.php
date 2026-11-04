<?php

namespace Tests\Browser\E821C;

use App\Vision\Site\Jobs\AuditSiteJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ProgressIsolationTest extends TenancyTestCase
{
    public function test_domain_status_is_scoped_to_the_current_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('audit_runs')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'created_by' => $owner->id, 'result' => json_encode(['url' => 'https://example.test']), 'created_at' => now(), 'updated_at' => now()]);
        }
        Cache::put(AuditSiteJob::key($org->id, $brand->id), ['stage' => 'complete', 'events' => [['stage' => 'complete', 'at' => '2026-11-03T12:00:00Z']]]);
        DB::table('brand_domains')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'host' => 'example.test', 'purpose' => 'widget_and_visibility', 'verification_method' => 'dns', 'verification_token' => 'secret', 'status' => 'verified', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/vision/site/bots')->assertOk()->assertInertia(fn ($page) => $page->where('domainStatus.host', 'example.test')->where('domainStatus.verified', true)->where('auditProgress.events.0.stage', 'complete')->missing('domainStatus.verification_token'));
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/vision/site/bots')->assertOk()->assertInertia(fn ($page) => $page->where('domainStatus.verified', false)->where('auditProgress', null));
        $this->post('/logout')->assertRedirect();
        $this->get('/vision/site/bots')->assertRedirect('/login');
    }
}
