<?php

namespace Tests\Feature\Vision\Settings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class TrendsTest extends TenancyTestCase
{
    public function test_empty_trends_are_scoped_to_selected_brand_and_require_access(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/vision/trends')->assertOk()->assertInertia(fn ($page) => $page->component('Vision/Settings/Trends')->has('rows', 0)->where('error', false));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/vision/trends')->assertOk()->assertInertia(fn ($page) => $page->has('rows', 0));
        $this->post('/logout')->assertRedirect();
        $this->get('/vision/trends')->assertRedirect();
    }
}
