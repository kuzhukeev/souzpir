<?php

namespace Tests\Feature\Seller\Catalog;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class PriceOptionsE608FTest extends TenancyTestCase
{
    public function test_options_are_saved_per_brand_and_require_permission(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/integrations/stock', ['catalog_options' => true, 'prices_enabled' => true, 'discounts_enabled' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/integrations/catalog')->assertOk()->assertInertia(fn ($page) => $page->where('priceOptions.prices_enabled', true)->where('priceOptions.discounts_enabled', true));
        $this->post('/seller/integrations/stock', ['catalog_options' => true, 'prices_enabled' => 'invalid', 'discounts_enabled' => true])->assertSessionHasErrors('prices_enabled');
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/integrations/catalog')->assertOk()->assertInertia(fn ($page) => $page->where('priceOptions.prices_enabled', false)->where('priceOptions.discounts_enabled', false));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/integrations/stock', ['catalog_options' => true, 'prices_enabled' => false, 'discounts_enabled' => false])->assertForbidden();
    }
}
