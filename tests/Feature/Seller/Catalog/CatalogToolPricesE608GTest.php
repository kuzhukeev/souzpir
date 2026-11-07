<?php

namespace Tests\Feature\Seller\Catalog;

use App\Seller\Engine\ToolRunner;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class CatalogToolPricesE608GTest extends TenancyTestCase
{
    private function seedItem(string $orgId, string $brandId, string $ownerId): void
    {
        $import = (string) Str::ulid();
        DB::table('catalog_imports')->insert(['id' => $import, 'organization_id' => $orgId, 'brand_id' => $brandId,
            'filename' => 'test.csv', 'status' => 'completed', 'item_count' => 1, 'created_by' => $ownerId,
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('catalog_items')->insert(['id' => (string) Str::ulid(), 'organization_id' => $orgId, 'brand_id' => $brandId,
            'import_id' => $import, 'sku' => 'A-1', 'name' => 'Салют Север', 'stock' => '12.000',
            'price' => 900, 'wholesale_price' => 700, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_check_stock_omits_prices_by_default(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->seedItem($org->id, $brand->id, $owner->id);
        $result = app(ToolRunner::class)->run('check_stock', ['query' => 'Салют'], new TenantContext($org->id, $brand->id));
        $this->assertArrayNotHasKey('price', $result['items'][0]);
        $this->assertArrayNotHasKey('wholesale_price', $result['items'][0]);
    }

    public function test_check_stock_exposes_price_and_discount_only_when_brand_enables_them(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->seedItem($org->id, $brand->id, $owner->id);
        DB::table('brand_seller_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id,
            'brand_id' => $brand->id, 'followup' => '{}', 'off_topic' => '{}', 'negative' => '{}',
            'tools' => json_encode(['catalog_discounts_enabled' => true]), 'greetings' => '{}', 'phrases' => '{}',
            'schedule' => '{}', 'assignment' => '{}', 'catalog_prices_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $tenant = new TenantContext($org->id, $brand->id);
        $result = app(ToolRunner::class)->run('check_stock', ['query' => 'Салют'], $tenant);
        $this->assertSame(900.0, $result['items'][0]['price']);
        $this->assertSame(700.0, $result['items'][0]['wholesale_price']);
    }
}
