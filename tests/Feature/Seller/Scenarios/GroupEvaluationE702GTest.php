<?php

namespace Tests\Feature\Seller\Scenarios;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class GroupEvaluationE702GTest extends TenancyTestCase
{
    public function test_unavailable_evaluation_is_scoped_and_does_not_fabricate_a_score(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('seller_scenario_run_groups')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'status' => 'completed', 'items' => json_encode([['name' => 'Тест', 'status' => 'completed', 'evaluation' => 'unavailable']]), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/quality/runs')->assertOk()->assertInertia(fn ($page) => $page
            ->where('groups.0.items.0.evaluation', 'unavailable')->where('groups.0.items.0.average', null)->where('groups.0.average', null));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/quality/runs')->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/seller/quality/runs')->assertOk()->assertInertia(fn ($page) => $page->has('groups', 0));
    }
}
