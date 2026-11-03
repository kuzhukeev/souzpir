<?php

namespace Tests\Feature\SellerRuntime\Delivery;

use App\Seller\Engine\ToolRunner;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class CatalogToolsTest extends TenancyTestCase
{
    public function test_stock_uses_latest_completed_import_of_current_brand_without_prices(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $import = function (string $brandId, string $name, string $stock) use ($owner, $org): void {
            $id = (string) Str::ulid();
            DB::table('catalog_imports')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brandId,
                'filename' => 'test.csv', 'status' => 'completed', 'item_count' => 1, 'created_by' => $owner->id,
                'created_at' => now(), 'updated_at' => now()]);
            DB::table('catalog_items')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id,
                'brand_id' => $brandId, 'import_id' => $id, 'sku' => 'A-1', 'name' => $name,
                'stock' => $stock, 'price' => 900, 'created_at' => now(), 'updated_at' => now()]);
        };
        $import($brand->id, 'Салют Север', '12.000');
        $import($other->id, 'Салют Юг', '31.000');
        $runner = new ToolRunner;
        $context = new TenantContext($org->id, $brand->id, $owner->id);

        $result = $runner->run('check_stock', ['query' => 'Салют'], $context);
        $this->assertSame('completed', $result['status']);
        $this->assertSame([['sku' => 'A-1', 'name' => 'Салют Север', 'stock' => 12.0]], $result['items']);
        $this->assertArrayNotHasKey('price', $result['items'][0]);
        $this->assertSame(['status' => 'invalid_arguments'], $runner->run('check_stock', ['query' => ' '], $context));
    }

    public function test_price_requires_opt_in_and_file_and_never_sends_live(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $context = new TenantContext($org->id, $brand->id, $owner->id);
        $runner = new ToolRunner;
        $arguments = ['email' => 'buyer@example.test'];
        $this->assertSame(['status' => 'unavailable'], $runner->run('send_price', $arguments, $context));
        DB::table('brand_seller_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id,
            'brand_id' => $brand->id, 'followup' => '{}', 'off_topic' => '{}', 'negative' => '{}', 'tools' => '{}',
            'greetings' => '{}', 'phrases' => '{}', 'schedule' => '{}', 'assignment' => '{}',
            'catalog_prices_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(['status' => 'unavailable'], $runner->run('send_price', $arguments, $context));
        DB::table('catalog_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id,
            'brand_id' => $brand->id, 'price_file_path' => 'seller/catalog/test.pdf', 'price_file_name' => 'Прайс.pdf',
            'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(['status' => 'fake_sent', 'file' => 'Прайс.pdf'], $runner->run('send_price', $arguments, $context));
        $this->assertSame(['status' => 'invalid_arguments'], $runner->run('send_price', ['email' => 'bad'], $context));
    }
}
