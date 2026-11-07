<?php

namespace Tests\Feature\Shell\Integrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;
use Tests\Support\MfaTestDatabase;

final class PriceMailOverviewE110HTest extends TenancyTestCase
{
    protected function beforeRefreshingDatabase(): void
    {
        MfaTestDatabase::assert();
    }

    public function test_price_mail_tile_is_scoped_and_only_links_authorized_users(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('seller_email_price_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'sender_email' => 'sender@example.test', 'sender_name' => 'Бренд', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/integrations')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('connections.8.id', 'price_email')->where('connections.8.status', 'configured')->where('connections.8.connected_at', null)->where('connections.8.href', route('seller.channels.email.index'))->where('connections.1.href', route('seller.widget.index'))->where('connections.9.id', 'crm_webhook')->where('connections.9.href', route('seller.integrations.crm')));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/integrations')->assertOk()->assertInertia(fn (Assert $page) => $page->where('connections.8.status', 'disconnected'));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/integrations')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('connections.8.status', 'configured')->where('connections.8.href', null)->where('connections.8.settings_restricted', true)->where('connections.9.href', route('seller.integrations.crm')));
        $this->get('/seller/channels/email')->assertForbidden();
        Http::assertNothingSent();
    }
}
