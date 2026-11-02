<?php

namespace Tests\Feature\Seller\Catalog;

use App\Vision\SiteAudit\PublicAddressResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class YmlFeedTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        app()->instance(PublicAddressResolver::class, new class implements PublicAddressResolver
        {
            public function addresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
    }

    public function test_fake_yml_import_schedule_and_failed_refresh_preserve_catalog(): void
    {
        Carbon::setTestNow('2026-11-02 12:00:00+00');
        try {
            [$owner, $org, $brand, $other] = $this->cabinet();
            $this->enable($org->id, $brand->id);
            $this->enable($org->id, $other->id);
            $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
            $this->post('/seller/integrations/catalog/yml', ['url' => 'https://example.com/feed.yml', 'interval_hours' => 1])->assertRedirect();
            $xml = '<yml_catalog><shop><categories><category id="7">Праздник</category></categories><offers><offer id="A1" available="true"><name>Хлопушка</name><price>100.50</price><currencyId>RUB</currencyId><categoryId>7</categoryId><count>3</count></offer></offers></shop></yml_catalog>';
            Http::fake(['https://example.com/feed.yml' => Http::sequence()->push($xml, 200)->push('Ошибка', 500)]);
            $this->post('/seller/integrations/catalog/yml/refresh')->assertRedirect();
            $this->assertDatabaseHas('catalog_items', ['brand_id' => $brand->id, 'name' => 'Хлопушка', 'price' => '100.50', 'stock' => '3.000']);
            $this->get('/seller/integrations/catalog')->assertOk()->assertInertia(fn ($page) => $page->where('items.0.name', 'Хлопушка')->where('yml.interval_hours', 1));
            Carbon::setTestNow(now()->addMinutes(59));
            $this->artisan('seller:refresh-yml-feeds')->assertSuccessful();
            Http::assertSentCount(1);
            Carbon::setTestNow(now()->addMinutes(2));
            $this->artisan('seller:refresh-yml-feeds')->assertSuccessful();
            Http::assertSentCount(2);
            $this->assertDatabaseHas('catalog_imports', ['brand_id' => $brand->id, 'status' => 'failed']);
            $this->get('/seller/integrations/catalog')->assertOk()->assertInertia(fn ($page) => $page->where('items.0.name', 'Хлопушка'));
            $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
            $this->get('/seller/integrations/catalog')->assertOk()->assertInertia(fn ($page) => $page->where('yml', null)->has('items', 0));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_invalid_url_xml_and_permissions(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/integrations/catalog/yml', ['url' => 'http://example.com/feed.yml', 'interval_hours' => 1])->assertSessionHasErrors('url');
        $this->post('/seller/integrations/catalog/yml', ['url' => 'https://example.com/feed.yml?token=secret', 'interval_hours' => 1])->assertSessionHasErrors('url');
        $this->post('/seller/integrations/catalog/yml', ['url' => 'https://example.com/feed.yml'])->assertRedirect();
        Http::fake(['https://example.com/feed.yml' => Http::response('<broken', 200)]);
        $this->post('/seller/integrations/catalog/yml/refresh')->assertSessionHasErrors('url');
        $this->assertDatabaseCount('catalog_items', 0);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/integrations/catalog/yml', ['url' => 'https://example.com/feed.yml'])->assertForbidden();
        $this->post('/seller/integrations/catalog/yml/refresh')->assertForbidden();
    }
}
