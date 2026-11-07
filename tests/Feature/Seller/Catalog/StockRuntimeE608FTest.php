<?php

namespace Tests\Feature\Seller\Catalog;

use App\Seller\Engine\ToolCatalog;
use App\Seller\Engine\ToolRunner;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class StockRuntimeE608FTest extends TenancyTestCase
{
    public function test_runtime_exposes_catalog_search_and_empty_results_without_cross_brand_data(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/integrations/catalog/import', ['file' => UploadedFile::fake()->createWithContent('catalog.csv', "Артикул;Название;Остаток\nA1;Салют;0\n")])->assertRedirect();
        $runner = app(ToolRunner::class);
        $tenant = new TenantContext($org->id, $brand->id);
        $found = $runner->run('check_stock', ['query' => 'A1'], $tenant);
        self::assertFalse($found['empty']);
        self::assertSame(0.0, $found['items'][0]['stock']);
        self::assertSame(['status' => 'completed', 'empty' => true, 'items' => []], $runner->run('check_stock', ['query' => 'B2'], $tenant));
        self::assertSame(['status' => 'unavailable'], $runner->run('check_stock', ['query' => 'A1'], new TenantContext($org->id, $other->id)));
        self::assertStringContainsString('каталоге текущего бренда', ToolCatalog::definitionsFor(['check_stock'])[0]['function']['description']);
    }

    public function test_catalog_reports_list_loading_errors_without_showing_empty_state(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/integrations/catalog/import', ['file' => UploadedFile::fake()->createWithContent('catalog.csv', "Артикул;Название\nA1;Салют\n")])->assertRedirect();
        DB::listen(function ($query): void {
            if (str_contains($query->sql, 'select') && str_contains($query->sql, 'catalog_items')) {
                throw new QueryException('pgsql', $query->sql, [], new \RuntimeException('fixture error'));
            }
        });
        $this->get('/seller/integrations/catalog')->assertOk()->assertInertia(fn ($page) => $page->where('itemsError', true)->has('items', 0));
    }
}
