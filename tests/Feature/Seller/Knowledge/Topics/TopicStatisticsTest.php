<?php

namespace Tests\Feature\Seller\Knowledge\Topics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class TopicStatisticsTest extends TenancyTestCase
{
    public function test_rates_and_questions_count_only_own_brand_and_correct_roles(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            $conversation = (string) Str::ulid();
            DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $org->id, 'brand_id' => $item->id, 'created_at' => now(), 'updated_at' => now()]);
            foreach ([['client', 'Вопрос', ['intent' => 'delivery']], ['agent', 'Ответ', ['intent' => 'delivery', 'goal_kind' => 'unknown_fact']], ['agent', 'Ответ 2', ['intent' => 'delivery', 'goal_kind' => 'answer_only']]] as [$role, $text, $meta]) {
                DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'conversation_id' => $conversation, 'role' => $role, 'text' => $text, 'meta' => json_encode($meta), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/knowledge/topics')->assertOk()->assertInertia(fn ($page) => $page->where('frequencies.delivery', 1)->where('unknownRates.delivery', 50)->where('frequentQuestions.delivery.0', 'Вопрос'));
        [$viewer] = $this->member($org, 'viewer', [$other->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $other->id])->post('/seller/knowledge/topics', ['version' => 1, 'title' => 'Тема', 'description' => 'Описание', 'after_action' => 'none'])->assertForbidden();
    }
}
