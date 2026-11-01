<?php

namespace Tests\Feature\Seller\Channels\Crm;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Seller\Settings\Limits\LimitsTestCase;

final class CrmWebhookViewTest extends LimitsTestCase
{
    public function test_only_current_brand_crm_webhooks_and_safe_journal_are_visible(): void
    {
        [$owner, $org, $first, $second] = $this->cabinet();
        $id = (string) Str::ulid();
        foreach ([[$id, $first, 'seller.contact.captured'], [(string) Str::ulid(), $first, 'vision.report.published'], [(string) Str::ulid(), $second, 'seller.contact.captured']] as [$hook, $brand, $event]) {
            DB::table('webhooks')->insert(['id' => $hook, 'organization_id' => $org->id, 'brand_id' => $brand->id,
                'url' => 'private-url', 'secret' => 'private-secret', 'events' => json_encode([$event]), 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('webhook_deliveries')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $first->id,
            'webhook_id' => $id, 'event' => 'webhook.test', 'payload' => '{}', 'status' => 'simulated', 'status_code' => 200, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $first->id])->withHeader('X-Brand-Id', $first->id);
        $this->get('/seller/integrations/crm')->assertOk()->assertDontSee('private-url')->assertDontSee('private-secret')
            ->assertInertia(fn (Assert $page) => $page->where('canManage', true)->has('webhooks', 1)
                ->where('webhooks.0.id', $id)->where('webhooks.0.deliveries.0.status', 'simulated'));
    }

    public function test_user_without_settings_access_cannot_view_the_page(): void
    {
        [, $org, $brand] = $this->cabinet();
        [$manager] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        $this->actingAs($manager)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id)
            ->get('/seller/integrations/crm')->assertForbidden();
    }
}
