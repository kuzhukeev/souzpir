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
}
