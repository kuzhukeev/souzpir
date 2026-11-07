<?php

namespace Tests\Feature\Shell\Integrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;
use Tests\Support\MfaTestDatabase;

final class CrmWebhookTileE110HTest extends TenancyTestCase
{
    protected function beforeRefreshingDatabase(): void
    {
        MfaTestDatabase::assert();
    }

    public function test_crm_webhook_is_a_tile_in_the_grid_scoped_to_brand_with_connection_date(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/integrations')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('connections.9.id', 'crm_webhook')->where('connections.9.status', 'disconnected')->where('connections.9.connected_at', null));

        DB::table('webhooks')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'url' => 'https://example.test/hook', 'secret' => 'secret', 'events' => json_encode(['seller.contact.captured']),
            'created_at' => '2026-10-20 00:00:00+00', 'updated_at' => '2026-10-20 00:00:00+00']);
        DB::table('webhooks')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $other->id,
            'url' => 'https://example.test/hook2', 'secret' => 'secret', 'events' => json_encode(['seller.contact.captured']),
            'created_at' => now(), 'updated_at' => now()]);

        $this->get('/integrations')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('connections.9.status', 'connected')
                ->where('connections.9.connected_at', '2026-10-20 00:00:00+00')
                ->where('connections.9.href', route('seller.integrations.crm')));

        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/integrations')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('connections.9.status', 'connected'));

        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/integrations')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('connections.9.status', 'connected')->where('connections.9.href', route('seller.integrations.crm')));
        Http::assertNothingSent();
    }
}
