<?php

namespace Tests\Feature\Seller\Analytics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class KpiTrendE704PTest extends TenancyTestCase
{
    public function test_previous_period_metrics_for_kpi_trend_badges_respect_brand_isolation(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $addDone = function (string $brandId, string $createdAt, int $messages, ?string $contactReceivedAt) use ($org): void {
            $id = (string) Str::ulid();
            DB::table('conversations')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brandId, 'stage' => 'done', 'state' => '{}', 'created_at' => $createdAt, 'updated_at' => now(), 'contact_received_at' => $contactReceivedAt]);
            for ($i = 0; $i < $messages; $i++) {
                DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brandId, 'conversation_id' => $id, 'role' => 'agent', 'text' => 'Ответ', 'meta' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            }
        };
        // current period (2026-11-02): 2 dialogs, 1 message each, one with contact in 10 min
        $addDone($brand->id, '2026-11-02 10:00:00+00', 1, '2026-11-02 10:10:00+00');
        $addDone($brand->id, '2026-11-02 11:00:00+00', 1, null);
        // previous period (2026-11-01): 1 dialog, 2 messages, contact in 5 min
        $addDone($brand->id, '2026-11-01 10:00:00+00', 2, '2026-11-01 10:05:00+00');
        // other brand previous period: must not leak into the brand's previous metrics
        $addDone($other->id, '2026-11-01 10:00:00+00', 5, '2026-11-01 10:01:00+00');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/analytics?from=2026-11-02&to=2026-11-02')->assertOk()->assertInertia(fn ($page) => $page
            ->where('analytics.total', 2)->where('analytics.messageCount', 2)->where('analytics.botCompleted', 2)->where('analytics.avgMinutesToContact', 10)
            ->where('analytics.previousTotal', 1)->where('analytics.previousMessageCount', 2)->where('analytics.previousBotCompleted', 1)->where('analytics.previousAvgMinutesToContact', 5));
    }
}
