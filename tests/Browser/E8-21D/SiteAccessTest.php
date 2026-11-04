<?php

namespace Tests\Browser\E821D;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SiteAccessTest extends TenancyTestCase
{
    public function test_audit_evidence_stays_with_its_brand_and_viewer_cannot_start_audit(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        [$owner, $organization, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('audit_runs')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'brand_id' => $brand->id, 'created_by' => $owner->id, 'result' => json_encode(['url' => 'https://one.example.com', 'criteria' => [['id' => 4, 'title' => 'Структурированные данные', 'evidence' => [['url' => 'https://one.example.com/contact', 'excerpt' => '{"@type":"ContactPage"}']]]], 'pages' => [['url' => 'https://one.example.com/contact', 'status' => 200]]], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/vision/site/pages')->assertOk()->assertInertia(fn ($page) => $page->where('audit.pages.0.url', 'https://one.example.com/contact')->where('audit.criteria.0.evidence.0.excerpt', '{"@type":"ContactPage"}'));
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/vision/site/pages')->assertOk()->assertInertia(fn ($page) => $page->where('audit', null));
        [$viewer] = $this->member($organization, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/vision/site/audit', ['url' => 'https://one.example.com'])->assertForbidden();
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }
}
