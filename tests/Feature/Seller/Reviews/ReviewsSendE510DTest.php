<?php

namespace Tests\Feature\Seller\Reviews;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ReviewsSendE510DTest extends TenancyTestCase
{
    public function test_selected_feedback_is_sent_to_knowledge_inbox_without_changing_knowledge(): void
    {
        Http::preventStrayRequests();
        config(['runtime.ai_driver' => 'fake']);
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $make = function (string $brandId, string $type, string $status = 'new') use ($owner, $org): string {
            $dialog = (string) Str::ulid();
            $message = (string) Str::ulid();
            $id = (string) Str::ulid();
            DB::table('conversations')->insert(['id' => $dialog, 'organization_id' => $org->id, 'brand_id' => $brandId, 'state' => '{}', 'mode' => 'manager', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('messages')->insert(['id' => $message, 'organization_id' => $org->id, 'brand_id' => $brandId, 'conversation_id' => $dialog, 'role' => 'agent', 'text' => 'Original response', 'meta' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('message_reviews')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brandId, 'message_id' => $message, 'user_id' => $owner->id, 'type' => $type, 'note' => 'Human comment', 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);

            return $id;
        };
        $ids = array_map(fn (string $type) => $make($brand->id, $type), ['unclear', 'bureaucratic', 'too_long', 'wrong_tone', 'other']);
        $dismissed = $make($brand->id, 'unclear', 'dismissed');
        $foreign = $make($other->id, 'other');
        $knowledge = DB::table('knowledge_releases')->count();

        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/reviews/send', ['ids' => [$ids[0], $foreign]])->assertNotFound();
        $this->post('/seller/reviews/send', ['ids' => [$dismissed]])->assertNotFound();
        $this->post('/seller/reviews/send', ['ids' => []])->assertSessionHasErrors('ids');
        $this->post('/seller/reviews/send', ['ids' => ['bad']])->assertSessionHasErrors('ids.0');
        $this->assertDatabaseMissing('message_reviews', ['applied_to' => 'inbox']);

        $this->from('/seller/reviews')->post('/seller/reviews/send', ['ids' => $ids])->assertRedirect('/seller/reviews')->assertSessionHas('status', 'Отправлено во Входящие базы знаний: 5.');
        foreach ($ids as $id) {
            $this->assertDatabaseHas('message_reviews', ['id' => $id, 'status' => 'new', 'applied_to' => 'inbox']);
        }
        $this->assertDatabaseHas('message_reviews', ['id' => $foreign, 'applied_to' => null]);
        self::assertSame($knowledge, DB::table('knowledge_releases')->count());
        $this->assertDatabaseCount('kn_facts', 0);
        Http::assertNothingSent();

        $inbox = collect($this->get('/seller/knowledge')->assertOk()->inertiaProps('reviews'))->map(fn ($row) => (array) $row)->keyBy('id');
        foreach ($ids as $id) {
            self::assertSame('Human comment', $inbox[$id]['note']);
        }
        $rows = collect($this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Seller/Reviews/Index', 'X-Inertia-Partial-Data' => 'reviews'])->get('/seller/reviews')->json('props.reviews.items'))->keyBy('id');
        self::assertSame('inbox', $rows[$ids[0]]['applied_to']);

        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/reviews/send', ['ids' => [$ids[0]]])->assertForbidden();
    }
}
