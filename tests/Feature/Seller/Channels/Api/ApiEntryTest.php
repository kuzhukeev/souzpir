<?php

namespace Tests\Feature\Seller\Channels\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ApiEntryTest extends TenancyTestCase
{
    public function test_settings_and_failure_log_share_permission_and_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        Http::fake();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/channels/api')->assertOk()->assertInertia(fn ($page) => $page->component('Seller/Channels/Api/Index'));
        $this->get('/seller/channels/api/failures')->assertOk()->assertSee('Отклонённых запросов пока нет.');
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/channels/api/failures')->assertOk()->assertSee('Отклонённых запросов пока нет.');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/channels/api')->assertForbidden();
        $this->get('/seller/channels/api/failures')->assertForbidden();
        Http::assertNothingSent();
    }
}
