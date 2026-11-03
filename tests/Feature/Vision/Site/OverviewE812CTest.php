<?php

namespace Tests\Feature\Vision\Site;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class OverviewE812CTest extends TenancyTestCase
{
    public function test_latest_and_previous_audits_are_limited_to_selected_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([[$brand, 40, '2026-10-01 12:00:00'], [$brand, 60, '2026-10-02 12:00:00'], [$other, 99, '2026-10-03 12:00:00']] as [$item, $score, $date]) {
            DB::table('audit_runs')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'created_by' => $owner->id, 'result' => json_encode(['url' => 'https://example.test', 'readiness' => $score]), 'created_at' => $date, 'updated_at' => $date]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/vision/site')->assertOk()->assertInertia(fn ($page) => $page->where('audit.readiness', 60)->where('previousAudit.readiness', 40)->has('auditDate'));
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/vision/site')->assertOk()->assertInertia(fn ($page) => $page->where('audit.readiness', 99)->where('previousAudit', null));
        $this->post('/logout')->assertRedirect();
        $this->get('/vision/site')->assertRedirect('/login');
    }
}
