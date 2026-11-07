<?php

namespace Tests\Feature\Seller\Analytics;

use App\Seller\Runtime\StageTransitions;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class HistoricalFunnelTest extends TenancyTestCase
{
    public function test_transitions_period_channel_and_brand_scope(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $channel = (string) Str::ulid();
        DB::table('channel_accounts')->insert(['id' => $channel, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'type' => 'telegram', 'created_at' => now(), 'updated_at' => now()]);
        $ids = [];
        foreach ([[$brand, $channel], [$brand, null], [$other, null]] as [$item, $account]) {
            $id = (string) Str::ulid();
            $ids[] = $id;
            DB::table('conversations')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $item->id, 'channel_account_id' => $account, 'stage' => 'need', 'created_at' => '2026-11-02 12:00:00+00', 'updated_at' => now()]);
        }
        $this->travelTo(now()->setDate(2026, 11, 2)->setTime(12, 0));
        $events = app(StageTransitions::class);
        $events->record(new TenantContext($org->id, $brand->id), $ids[0], 'open', 'need');
        $events->record(new TenantContext($org->id, $brand->id), $ids[0], 'need', 'need');
        $events->record(new TenantContext($org->id, $brand->id), $ids[1], 'open', 'budget');
        $events->record(new TenantContext($org->id, $other->id), $ids[2], 'open', 'need');
        $this->assertDatabaseCount('seller_stage_events', 3);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/analytics?from=2026-11-02&to=2026-11-02&channel=telegram&source=unknown')->assertOk()->assertInertia(fn ($page) => $page->where('analytics.total', 1)->where('analytics.transitions.need', 1)->missing('analytics.transitions.budget')->where('analytics.sources.unknown', 1));
        $this->get('/seller/analytics?from=2026-11-03&to=2026-11-03')->assertOk()->assertInertia(fn ($page) => $page->where('analytics.total', 0)->where('analytics.transitions', []));
        $this->get('/seller/analytics?source=madeup')->assertSessionHasErrors('source');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/analytics?from=2026-11-02&to=2026-11-02')->assertOk()->assertInertia(fn ($page) => $page->where('analytics.canManage', false));
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/seller/analytics?from=2026-11-02&to=2026-11-02')->assertOk()->assertInertia(fn ($page) => $page->where('analytics.total', 1)->where('analytics.transitions.need', 1));
    }
}
