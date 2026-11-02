<?php

namespace Tests\Feature\Seller\Quality;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class QualityLinksTest extends TenancyTestCase
{
    public function test_day_exposes_only_brand_evaluations_and_handles_empty_and_permissions(): void
    {
        [$owner,$org,$brand,$other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $id = (string) Str::ulid();
        DB::table('seller_evaluations')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'scores' => '{}', 'issues' => '[{"type":"unknown_fact","layer":"knowledge","severity":"medium","turn":1,"quote":"Цена?","explanation":"Нет факта","fix":"Уточнить"}]', 'average' => 3, 'created_at' => '2026-11-02 12:00:00+00', 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/quality/2026-11-02')->assertOk()->assertInertia(fn ($page) => $page->has('evaluations', 1)->where('evaluations.0.id', $id)->where('evaluations.0.issues.0.fix', 'Уточнить'));
        $this->get('/seller/quality/2026-11-01')->assertOk()->assertInertia(fn ($page) => $page->has('evaluations', 0));
        $this->get('/seller/quality/2026-02-30')->assertNotFound();
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/quality/2026-11-02')->assertOk()->assertInertia(fn ($page) => $page->has('evaluations', 0));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/quality/2026-11-02')->assertForbidden();
    }
}
