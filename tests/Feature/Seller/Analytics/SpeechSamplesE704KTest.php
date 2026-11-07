<?php

namespace Tests\Feature\Seller\Analytics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SpeechSamplesE704KTest extends TenancyTestCase
{
    public function test_only_confirmed_review_examples_are_attributed_to_the_handler(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$handler] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([['example', 'accept'], ['fact', 'accept'], ['example', 'reject']] as [$target, $decision]) {
            $conversation = (string) Str::ulid();
            $message = (string) Str::ulid();
            $review = (string) Str::ulid();
            DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'mode' => 'manager', 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('messages')->insert(['id' => $message, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $conversation, 'role' => 'manager', 'text' => 'Пример', 'meta' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('message_reviews')->insert(['id' => $review, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'message_id' => $message, 'user_id' => $owner->id, 'type' => 'wrong_fact', 'status' => 'done', 'applied_to' => $target, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('audit_logs')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'actor_id' => $handler->id, 'action' => 'knowledge.incoming.handled', 'subject_type' => 'review', 'subject_id' => $review, 'after' => json_encode(['decision' => $decision]), 'created_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/analytics?manager='.$handler->id)->assertOk()->assertInertia(fn ($page) => $page->where('analytics.managers.0.name', $handler->name)->where('analytics.managers.0.samples', 1));
        $this->getJson('/seller/analytics?from=bad')->assertUnprocessable();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/analytics?manager='.$handler->id)->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/seller/analytics')->assertOk()->assertInertia(fn ($page) => $page->has('analytics.managers', 0));
    }
}
