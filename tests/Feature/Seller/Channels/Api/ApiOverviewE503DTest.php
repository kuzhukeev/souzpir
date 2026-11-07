<?php

namespace Tests\Feature\Seller\Channels\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ApiOverviewE503DTest extends TenancyTestCase
{
    public function test_recent_errors_are_brand_scoped_and_hide_request_data(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id,
                'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([[$brand->id, 422], [$other->id, 500]] as [$brandId, $status]) {
            DB::table('seller_api_request_failures')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brandId,
                'endpoint' => 'turn', 'http_status' => $status, 'category' => 'validation', 'created_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/channels/api')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Seller/Channels/Api/Index')->has('logs', 1)
                ->where('logs.0.endpoint', 'turn')->where('logs.0.status', 'error')->where('logs.0.error', 'HTTP 422'));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/channels/api')->assertForbidden();
    }
}
