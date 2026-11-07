<?php

namespace Tests\Feature\Vision\Reports;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ReportRightsE816STest extends TenancyTestCase
{
    public function test_viewer_can_read_and_manager_without_measurement_permission_is_denied(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/report')->assertOk()->inertiaProps('reportBrandRevision');
        $this->withHeaders(['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision])->getJson('/vision/report/data')->assertOk()->assertJsonPath('report', null);
        [$manager] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        $this->flushHeaders()->actingAs($manager)->withSession(['brand_id' => $brand->id])->get('/vision/report')->assertForbidden();
    }
}
