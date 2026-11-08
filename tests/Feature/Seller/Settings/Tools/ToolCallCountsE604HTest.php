<?php

namespace Tests\Feature\Seller\Settings\Tools;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ToolCallCountsE604HTest extends TenancyTestCase
{
    public function test_only_current_brand_confirmed_promise_events_in_the_last_30_days_are_counted(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $event = static function (string $brandId, string $action, $date) use ($org): void {
            DB::table('audit_logs')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brandId, 'action' => $action, 'subject_type' => 'seller_task', 'subject_id' => (string) Str::ulid(), 'created_at' => $date]);
        };
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/scenario/tools')->assertOk()->assertInertia(fn ($page) => $page->where('callCounts.create_promise', null));
        $event($brand->id, 'seller.promise.created', now()->subDays(2));
        $event($brand->id, 'seller.promise.created', now()->subDays(31));
        $event($brand->id, 'seller.promise.created', now()->addDay());
        $event($brand->id, 'seller.tools.settings.updated', now());
        $event($other->id, 'seller.promise.created', now());
        $event($other->id, 'seller.promise.created', now());
        $this->get('/seller/scenario/tools')->assertOk()->assertInertia(fn ($page) => $page->where('callCounts.create_promise', 1)->where('callCounts.send_price', null));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/scenario/tools')->assertOk()->assertInertia(fn ($page) => $page->where('callCounts.create_promise', 2));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/scenario/tools')->assertForbidden();
        Http::assertNothingSent();
    }
}
