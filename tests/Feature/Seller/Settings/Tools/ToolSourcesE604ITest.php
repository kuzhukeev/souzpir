<?php

namespace Tests\Feature\Seller\Settings\Tools;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ToolSourcesE604ITest extends TenancyTestCase
{
    public function test_sources_reflect_current_brand_files_catalog_and_permissions(): void
    {
        Http::preventStrayRequests();
        Storage::fake('local');
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/scenario/tools')->assertOk()->assertInertia(fn ($page) => $page->where('sources.price_file_available', false)->where('sources.catalog_available', false));
        $this->post('/seller/integrations/catalog/import', ['file' => UploadedFile::fake()->createWithContent('catalog.csv', "Название;Артикул;Остаток\nФейерверк;A1;3\n")])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/seller/integrations/catalog/price-file', ['file' => UploadedFile::fake()->createWithContent('price.csv', 'price')])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/scenario/tools')->assertOk()->assertInertia(fn ($page) => $page->where('sources.price_file_available', true)->where('sources.catalog_available', true));
        $path = DB::table('catalog_settings')->where('brand_id', $brand->id)->value('price_file_path');
        Storage::disk('local')->delete($path);
        $this->get('/seller/scenario/tools')->assertOk()->assertInertia(fn ($page) => $page->where('sources.price_file_available', false)->where('sources.catalog_available', true));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/scenario/tools')->assertOk()->assertInertia(fn ($page) => $page->where('sources.price_file_available', false)->where('sources.catalog_available', false));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/scenario/tools')->assertForbidden();
        Http::assertNothingSent();
    }
}
