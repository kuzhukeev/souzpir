<?php

namespace Tests\Feature\Seller\Catalog;

use App\Seller\Engine\ToolCatalog;
use App\Seller\Engine\ToolRunner;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class LowStockE608FTest extends TenancyTestCase
{
    public function test_check_stock_marks_low_stock_by_brand_threshold_only(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $b) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $b->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/integrations/catalog/import', ['file' => UploadedFile::fake()->createWithContent('catalog.csv', "Артикул;Название;Остаток\nA1;Ракета малая;3\nA2;Салют большой;40\nA3;Салют нет;0\n")])->assertRedirect();
        $runner = app(ToolRunner::class);
        $tenant = new TenantContext($org->id, $brand->id);
        self::assertArrayNotHasKey('low', $runner->run('check_stock', ['query' => 'A1'], $tenant)['items'][0]);

        $this->post('/seller/integrations/stock', ['low_stock' => 5])->assertRedirect();
        self::assertTrue($runner->run('check_stock', ['query' => 'A1'], $tenant)['items'][0]['low']);
        self::assertArrayNotHasKey('low', $runner->run('check_stock', ['query' => 'A2'], $tenant)['items'][0]);
        self::assertArrayNotHasKey('low', $runner->run('check_stock', ['query' => 'A3'], $tenant)['items'][0]);
        $this->get('/seller/integrations/stock')->assertOk()->assertInertia(fn ($page) => $page->where('stockExample.items.0.low', true));
        self::assertStringContainsString('признак low', ToolCatalog::definitionsFor(['check_stock'])[0]['function']['description']);

        // The threshold of one brand does not leak into another brand's catalog.
        $import = (string) Str::ulid();
        DB::table('catalog_imports')->insert(['id' => $import, 'organization_id' => $org->id, 'brand_id' => $other->id, 'filename' => 'other.csv', 'status' => 'completed', 'item_count' => 1, 'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('catalog_items')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $other->id, 'import_id' => $import, 'sku' => 'A1', 'name' => 'Салют', 'stock' => 3, 'created_at' => now(), 'updated_at' => now()]);
        self::assertArrayNotHasKey('low', $runner->run('check_stock', ['query' => 'A1'], new TenantContext($org->id, $other->id))['items'][0]);
    }

    public function test_import_history_keeps_source_and_error_and_rejects_bad_threshold(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/integrations/catalog/import', ['file' => UploadedFile::fake()->createWithContent('Каталог.csv', "Артикул;Название\nA1;Салют\n")])->assertRedirect();
        $this->post('/seller/integrations/stock', ['low_stock' => 'много'])->assertSessionHasErrors('low_stock');
        $this->get('/seller/integrations/catalog')->assertOk()->assertInertia(fn ($page) => $page->where('imports.0.filename', 'Каталог.csv')->where('imports.0.status', 'completed'));
    }
}
