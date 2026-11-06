<?php

namespace Tests\Feature\Vision\Site;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class BrandSiteE821LTest extends TenancyTestCase
{
    public function test_website_is_scoped_to_selected_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([[$brand, 'https://first.example'], [$other, 'https://second.example']] as [$item, $url]) {
            DB::table('brands')->where('id', $item->id)->update(['website' => $url]);
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/vision/site')->assertOk()->assertInertia(fn ($page) => $page->where('brandSite', 'https://first.example'));
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/vision/site')->assertOk()->assertInertia(fn ($page) => $page->where('brandSite', 'https://second.example'));
        $this->post('/logout')->assertRedirect();
        $this->get('/vision/site')->assertRedirect('/login');
    }
}
