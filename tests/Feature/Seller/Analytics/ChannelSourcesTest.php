<?php

namespace Tests\Feature\Seller\Analytics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ChannelSourcesTest extends TenancyTestCase
{
    public function test_channel_rating_and_contact_conversion_are_scoped_to_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            $account = (string) Str::ulid();
            $dialog = (string) Str::ulid();
            DB::table('channel_accounts')->insert(['id' => $account, 'organization_id' => $org->id, 'brand_id' => $item->id, 'type' => 'telegram', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('conversations')->insert(['id' => $dialog, 'organization_id' => $org->id, 'brand_id' => $item->id, 'channel_account_id' => $account, 'stage' => 'need', 'state' => json_encode(['slots' => ['email' => 'client@example.test']]), 'created_at' => '2026-11-02 12:00:00+00', 'updated_at' => now()]);
            DB::table('seller_evaluations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'conversation_id' => $dialog, 'scores' => '{}', 'issues' => '[]', 'average' => $item->id === $brand->id ? 4.2 : 1.0, 'created_at' => now(), 'updated_at' => now()]);
        }

        $response = $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/analytics?from=2026-11-02&to=2026-11-02')->assertOk();
        $metrics = collect($response->inertiaProps('analytics.channelMetrics'));
        $telegram = $metrics->firstWhere('channel', 'telegram');
        $this->assertSame(1, $telegram['dialogs']);
        $this->assertSame(1, $telegram['contacts']);
        $this->assertSame(100, (int) $telegram['conversion']);
        $this->assertSame(4.2, (float) $telegram['average_rating']);
        $this->assertTrue($metrics->firstWhere('channel', 'whatsapp')['planned']);
        $this->assertSame([], $response->inertiaProps('analytics.sitePages'));

        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/analytics?from=2026-11-02&to=2026-11-02')->assertOk();
        $this->get('/seller/analytics?from=bad')->assertSessionHasErrors('from');
        $this->post('/logout')->assertRedirect();
        $this->get('/seller/analytics')->assertRedirect();
    }
}
