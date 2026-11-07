<?php

namespace Tests\Feature\Seller\Analytics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class PeriodMetricsE704GTest extends TenancyTestCase
{
    public function test_period_metrics_respect_filters_permissions_and_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$manager] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $current = $this->conversation($org->id, $brand->id, $manager->id, '2026-11-02 12:00:00+00', 'full_contact');
        DB::table('conversations')->where('id', $current)->update(['stage' => 'done', 'state' => json_encode(['slots' => ['email' => 'lead@example.test']]), 'handed_off_at' => '2026-11-02 12:00:00+00', 'taken_at' => '2026-11-02 12:15:00+00']);
        $message = (string) Str::ulid();
        DB::table('messages')->insert(['id' => $message, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $current, 'role' => 'agent', 'text' => 'Ответ', 'meta' => json_encode(['goal' => ['trigger' => 'offer']]), 'created_at' => '2026-11-02 12:20:00+00', 'updated_at' => now()]);
        DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $current, 'role' => 'client', 'text' => 'Спасибо', 'meta' => '{}', 'created_at' => '2026-11-02 12:21:00+00', 'updated_at' => now()]);
        DB::table('message_reviews')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'message_id' => $message, 'user_id' => $manager->id, 'type' => 'other', 'created_at' => '2026-11-02 12:30:00+00', 'updated_at' => now()]);
        DB::table('seller_evaluations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $current, 'scores' => '{}', 'issues' => '[]', 'average' => 4.2, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seller_tasks')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $current, 'type' => 'unknown_question', 'payload' => json_encode(['question' => 'Как доставить?']), 'created_at' => now(), 'updated_at' => now()]);
        $previous = $this->conversation($org->id, $brand->id, $manager->id, '2026-11-01 12:00:00+00', 'declined');
        DB::table('conversations')->where('id', $previous)->update(['state' => json_encode(['slots' => ['messenger' => 'Telegram']]), 'handed_off_at' => '2026-11-01 13:00:00+00']);
        $this->conversation($org->id, $other->id, null, '2026-11-02 12:00:00+00', 'spam');
        foreach (['open', 'qualify'] as $stage) {
            DB::table('seller_stage_events')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $current, 'from_stage' => 'open', 'to_stage' => $stage, 'occurred_at' => '2026-11-02 13:00:00+00']);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $query = '?from=2026-11-02&to=2026-11-02&manager='.$manager->id;
        $this->get('/seller/analytics'.$query)->assertOk()->assertInertia(fn ($page) => $page
            ->where('analytics.total', 1)->where('analytics.previousTotal', 1)->where('analytics.previousMetrics.contact', 1)->where('analytics.previousMetrics.handed', 1)->where('analytics.previousMetrics.closed', 0)->where('analytics.outcomes.full_contact', 1)
            ->where('analytics.outcomes.spam', 0)->where('analytics.funnel.1.count', 1)->where('analytics.funnel.5.count', 1)->where('analytics.transitionRates.qualify', 100)->where('analytics.unknownDialogs', 1)
            ->where('analytics.triggerStats.offer.with_contact', 1)->where('analytics.managers.0.reaction_minutes', 15)
            ->where('analytics.managers.0.reviews', 1)->where('analytics.managers.0.score', 4.2)->has('analytics.managerOptions', 1));
        $this->getJson('/seller/analytics?manager=bad')->assertUnprocessable();
        $this->getJson('/seller/analytics?from=2026-11-03&to=2026-11-02')->assertUnprocessable();
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/analytics'.$query)->assertForbidden();
    }

    private function conversation(string $org, string $brand, ?string $manager, string $created, string $outcome): string
    {
        $id = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $id, 'organization_id' => $org, 'brand_id' => $brand, 'assigned_user_id' => $manager, 'stage' => 'open', 'outcome' => $outcome, 'state' => '{}', 'created_at' => $created, 'updated_at' => $created]);

        return $id;
    }
}
