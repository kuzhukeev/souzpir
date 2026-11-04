<?php

namespace Tests\Feature\Seller\Analytics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class AnalyticsDetailsTest extends TenancyTestCase
{
    public function test_details_use_selected_brand_and_period(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            $id = (string) Str::ulid();
            DB::table('conversations')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $item->id, 'stage' => 'open', 'created_at' => '2026-11-02 12:00:00+00', 'updated_at' => now()]);
            DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'conversation_id' => $id, 'role' => 'client', 'text' => 'Вопрос', 'meta' => json_encode(['extraction' => ['client_intent' => 'delivery'], 'goal' => ['trigger' => 'availability']]), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('seller_tasks')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'conversation_id' => $id, 'type' => 'unknown_question', 'payload' => json_encode(['question' => $item->id === $brand->id ? 'Где доставка?' : 'Чужой вопрос']), 'status' => 'new', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/analytics?from=2026-11-02&to=2026-11-02')->assertOk()->assertInertia(fn ($page) => $page->has('analytics.dialogs', 1)->where('analytics.dialogs.0.messages', 1)->where('analytics.messageCount', 1)->where('analytics.topics.delivery', 1)->where('analytics.triggers.availability', 1)->where('analytics.unknownQuestions.Где доставка?', 1)->missing('analytics.unknownQuestions.Чужой вопрос'));
        $this->get('/seller/analytics?from=2026-11-03&to=2026-11-03')->assertOk()->assertInertia(fn ($page) => $page->has('analytics.dialogs', 0)->where('analytics.messageCount', 0));
        $this->get('/seller/analytics?source=invalid')->assertSessionHasErrors('source');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/analytics?from=2026-11-02&to=2026-11-02')->assertOk()->assertInertia(fn ($page) => $page->where('analytics.canManage', false));
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/seller/analytics?from=2026-11-02&to=2026-11-02')->assertOk()->assertInertia(fn ($page) => $page->where('analytics.unknownQuestions.Чужой вопрос', 1)->missing('analytics.unknownQuestions.Где доставка?'));
    }
}
