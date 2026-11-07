<?php

namespace Tests\Feature\Seller\Catalog;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class CatalogTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_imports_csv_stores_price_file_and_stock_settings(): void
    {
        Storage::fake('local');
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/integrations/catalog/import', ['file' => UploadedFile::fake()->createWithContent('catalog.csv', "Артикул;Название;Категория;Цена;Цена опт;Остаток\nA1;Фейерверк;Праздник;100,50;80;3\n")])->assertRedirect();
        $this->assertDatabaseHas('catalog_items', ['brand_id' => $brand->id, 'name' => 'Фейерверк', 'sku' => 'A1', 'price' => '100.50', 'stock' => '3.000']);
        $this->get('/seller/integrations/catalog')->assertOk()->assertInertia(fn ($page) => $page->where('items.0.name', 'Фейерверк')->has('imports', 1));
        $this->post('/seller/integrations/stock', ['low_stock' => 5])->assertRedirect();
        $this->get('/seller/integrations/stock')->assertOk()->assertInertia(fn ($page) => $page->where('settings.low_stock', '5.000'));
        $this->post('/seller/integrations/catalog/price-file', ['file' => UploadedFile::fake()->createWithContent('prices.csv', 'price')])->assertRedirect();
        $settings = DB::table('catalog_settings')->where('brand_id', $brand->id)->first();
        self::assertSame('prices.csv', $settings->price_file_name);
        Storage::disk('local')->assertExists($settings->price_file_path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'seller.catalog.imported']);
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/integrations/catalog')->assertOk()->assertInertia(fn ($page) => $page->has('items', 0)->has('imports', 0));
    }

    public function test_deletes_item_within_brand_scope_only(): void
    {
        Storage::fake('local');
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/integrations/catalog/import', ['file' => UploadedFile::fake()->createWithContent('catalog.csv', "Артикул;Название;Категория;Цена;Цена опт;Остаток\nA1;Фейерверк;Праздник;100,50;80;3\n")])->assertRedirect();
        $item = DB::table('catalog_items')->where('brand_id', $brand->id)->first();
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->post('/seller/integrations/catalog/price-file', ['action' => 'delete_item', 'item_id' => $item->id])->assertNotFound();
        $this->post('/brand/switch', ['brand_id' => $brand->id])->assertRedirect();
        $this->post('/seller/integrations/catalog/price-file', ['action' => 'delete_item', 'item_id' => 'missing'])->assertNotFound();
        $this->post('/seller/integrations/catalog/price-file', ['action' => 'delete_item', 'item_id' => $item->id])->assertRedirect();
        $this->assertDatabaseMissing('catalog_items', ['id' => $item->id]);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/integrations/catalog/price-file', ['action' => 'delete_item', 'item_id' => $item->id])->assertForbidden();
    }

    public function test_invalid_files_and_access(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/integrations/catalog/import', ['file' => UploadedFile::fake()->createWithContent('bad.csv', "Название;Цена\nИзделие;-5\n")])->assertSessionHasErrors('file');
        $this->assertDatabaseHas('catalog_imports', ['brand_id' => $brand->id, 'status' => 'failed']);
        $this->assertDatabaseCount('catalog_items', 0);
        $this->post('/seller/integrations/stock', ['low_stock' => -1])->assertSessionHasErrors('low_stock');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/integrations/catalog')->assertForbidden();
        $this->post('/seller/integrations/catalog/import', ['file' => UploadedFile::fake()->createWithContent('ok.csv', "Название\nТовар")])->assertForbidden();
    }
}
