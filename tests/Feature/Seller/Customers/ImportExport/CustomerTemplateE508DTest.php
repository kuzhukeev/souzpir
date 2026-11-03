<?php

namespace Tests\Feature\Seller\Customers\ImportExport;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class CustomerTemplateE508DTest extends TenancyTestCase
{
    public function test_template_and_archive_respect_roles_and_brand(): void
    {
        Storage::fake('local');
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/customer-io/template')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->assertSee('Имя;Телефон;Почта;Город');
        $this->post('/settings/data/exports', ['module' => 'seller', 'from' => '2026-01-01', 'to' => '2026-01-02'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('data_exports', ['brand_id' => $brand->id, 'module' => 'seller']);
        $this->assertDatabaseMissing('data_exports', ['brand_id' => $other->id, 'module' => 'seller']);
        $this->post('/settings/data/exports', ['module' => 'seller', 'from' => '2026-01-02', 'to' => '2026-01-01'])->assertSessionHasErrors('to');
        $this->post('/settings/data/exports', ['module' => 'seller', 'from' => 'bad', 'to' => '2026-01-01'])->assertSessionHasErrors('from');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/customer-io/template')->assertForbidden();
        $this->post('/settings/data/exports', ['module' => 'seller', 'from' => '2026-01-01', 'to' => '2026-01-02'])->assertForbidden();
    }

    public function test_export_filters_latest_stage_and_respects_brand_and_format(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([[$brand, 'Свой клиент', 'qualifying'], [$brand, 'Другая стадия', 'done'], [$other, 'Чужой клиент', 'qualifying']] as [$item, $name, $stage]) {
            $id = (string) Str::ulid();
            DB::table('contacts')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $item->id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('conversations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'contact_id' => $id, 'stage' => $stage, 'state' => '{}', 'mode' => 'bot', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $range = ['from' => now()->subDay()->format('Y-m-d'), 'to' => now()->addDay()->format('Y-m-d'), 'stage' => 'qualifying'];
        $csv = $this->get('/seller/customer-io/export?'.http_build_query([...$range, 'format' => 'csv']))->assertOk();
        $content = file_get_contents($csv->baseResponse->getFile()->getPathname());
        self::assertStringContainsString('Свой клиент', $content);
        self::assertStringNotContainsString('Другая стадия', $content);
        self::assertStringNotContainsString('Чужой клиент', $content);
        $xlsx = $this->get('/seller/customer-io/export?'.http_build_query([...$range, 'format' => 'xlsx']))->assertOk();
        $zip = new \ZipArchive;
        self::assertTrue($zip->open($xlsx->baseResponse->getFile()->getPathname()));
        self::assertStringContainsString('Свой клиент', $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();
        $this->get('/seller/customer-io/export?'.http_build_query([...$range, 'format' => 'bad']))->assertSessionHasErrors('format');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/customer-io/export?'.http_build_query([...$range, 'format' => 'csv']))->assertForbidden();
    }
}
