<?php

namespace Tests\Feature\Seller\Catalog;

use App\Seller\Engine\ToolRunner;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class StockExampleE608ETest extends TenancyTestCase
{
    public function test_stock_example_uses_brand_catalog_and_checks_access(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/integrations/catalog/import', ['file' => UploadedFile::fake()->createWithContent('catalog.csv', "Артикул;Название;Остаток\nA1;Фейерверк;3\n")])->assertRedirect();
        $this->get('/seller/integrations/stock')->assertOk()->assertInertia(fn ($page) => $page
            ->where('stockExample.status', 'completed')->where('stockExample.items.0.name', 'Фейерверк')->where('stockExample.items.0.stock', 3));
        $runner = app(ToolRunner::class);
        self::assertSame('invalid_arguments', $runner->run('check_stock', ['query' => ''], new TenantContext($org->id, $brand->id))['status']);
        self::assertSame('unavailable', $runner->run('check_stock', ['query' => 'A1'], new TenantContext($org->id, $other->id))['status']);
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/integrations/stock')->assertOk()->assertInertia(fn ($page) => $page->where('stockExample', null));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/integrations/stock')->assertForbidden();
    }
}
