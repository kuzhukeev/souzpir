<?php

namespace Tests\Feature\Seller\Analytics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SubscriptionE704NTest extends TenancyTestCase
{
    public function test_subscription_is_validated_and_scoped_to_brand_and_permissions(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->post('/seller/analytics/subscription', [])->assertRedirect();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/analytics/subscription', ['day' => 'mon', 'recipients' => ['one@example.test']])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/analytics')->assertOk()->assertInertia(fn ($page) => $page->where('analytics.subscription.day', 'mon')->where('analytics.subscription.recipients.0', 'one@example.test'));
        $id = DB::table('seller_analytics_subscriptions')->where('brand_id', $brand->id)->value('id');
        $this->post('/seller/analytics/subscription', ['day' => 'fri', 'recipients' => ['two@example.test']])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('seller_analytics_subscriptions', ['id' => $id, 'brand_id' => $brand->id, 'day' => 'fri']);
        $this->postJson('/seller/analytics/subscription', ['day' => 'bad', 'recipients' => ['bad']])->assertUnprocessable();
        $this->postJson('/seller/analytics/subscription', ['day' => 'mon', 'recipients' => []])->assertUnprocessable();
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/analytics')->assertOk()->assertInertia(fn ($page) => $page->where('analytics.subscription', null));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/analytics/subscription', ['day' => 'mon', 'recipients' => ['one@example.test']])->assertForbidden();
    }
}
