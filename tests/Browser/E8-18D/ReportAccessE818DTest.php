<?php

namespace Tests\Browser\E818D;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ReportAccessE818DTest extends TenancyTestCase
{
    public function test_report_access_is_scoped_and_invalid_selection_is_rejected(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->get('/vision/report')->assertRedirect();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/report')->assertOk()->inertiaProps('reportBrandRevision');
        $this->withHeaders(['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision])->get('/vision/report/data?report=invalid')->assertNotFound();
        $this->withHeaders(['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision])->get('/vision/report/data?report[]=invalid')->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/vision/report')->assertOk();
        $this->actingAs($viewer)->withSession(['brand_id' => $other->id]);
        $this->get('/vision/report')->assertOk()->assertInertia(fn ($page) => $page->where('brand.id', $brand->id));
    }
}
