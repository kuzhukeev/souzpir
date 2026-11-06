<?php

namespace Tests\Feature\Seller\Dialogs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ReviewsE510DTest extends TenancyTestCase
{
    public function test_all_feedback_types_reach_inbox_and_require_an_explicit_action(): void
    {
        Http::preventStrayRequests();
        config(['runtime.ai_driver' => 'fake']);
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $make = function (string $brandId, string $type) use ($owner, $org): string {
            $dialog = (string) Str::ulid();
            $message = (string) Str::ulid();
            $id = (string) Str::ulid();
            DB::table('conversations')->insert(['id' => $dialog, 'organization_id' => $org->id, 'brand_id' => $brandId, 'state' => '{}', 'mode' => 'manager', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('messages')->insert(['id' => $message, 'organization_id' => $org->id, 'brand_id' => $brandId, 'conversation_id' => $dialog, 'role' => 'agent', 'text' => 'Original response', 'meta' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('message_reviews')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brandId, 'message_id' => $message, 'user_id' => $owner->id, 'type' => $type, 'note' => 'Human comment', 'status' => 'new', 'created_at' => now(), 'updated_at' => now()]);

            return $id;
        };
        $types = ['unclear', 'bureaucratic', 'too_long', 'wrong_fact', 'wrong_tone', 'other'];
        $ids = [];
        foreach ($types as $type) {
            $ids[$type] = $make($brand->id, $type);
        }
        $foreign = $make($other->id, 'other');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $this->post('/seller/knowledge/facts/group', ['version' => 1, 'key' => 'info'])->assertRedirect();
        $before = DB::table('knowledge_releases')->where('brand_id', $brand->id)->where('status', 'draft')->value('version');
        $reviews = collect($this->get('/seller/knowledge')->assertOk()->inertiaProps('reviews'))->keyBy('type');
        self::assertCount(6, $reviews);
        foreach ($types as $type) {
            self::assertSame('Original response', $reviews[$type]->message_text ?? $reviews[$type]['message_text']);
            self::assertSame('Human comment', $reviews[$type]->note ?? $reviews[$type]['note']);
        }
        Http::assertNothingSent();
        self::assertSame($before, DB::table('knowledge_releases')->where('brand_id', $brand->id)->where('status', 'draft')->value('version'));
        $payload = ['type' => 'review', 'id' => $ids['unclear'], 'decision' => 'accept', 'target' => 'fact', 'version' => 2, 'group' => 'info', 'text' => 'Approved fact'];
        $this->post('/seller/knowledge/incoming', [...$payload, 'id' => $foreign])->assertNotFound();
        $this->post('/seller/knowledge/incoming', [...$payload, 'version' => 1])->assertStatus(409);
        $this->assertDatabaseMissing('kn_facts', ['text' => 'Approved fact']);
        $this->post('/seller/knowledge/incoming', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('kn_facts', ['text' => 'Approved fact']);
        $this->assertDatabaseHas('message_reviews', ['id' => $ids['unclear'], 'status' => 'done']);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/knowledge/incoming', [...$payload, 'id' => $ids['other'], 'version' => 3])->assertForbidden();
    }
}
