<?php

namespace Tests\Feature\Seller\Dialogs;

use App\Models\Brand;
use App\Models\Organization;
use App\Seller\Conversations\CanonicalJson;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SuggestionActionsE504NTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
    }

    private function enableSeller(Organization $org, Brand $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array{0: object, 1: string} */
    private function readySuggestion(Organization $org, Brand $brand, string $text = 'Да, доставляем в Казань.', ?string $assignedUserId = null): array
    {
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $claim = app(TenantContextRunner::class)->run($tenant, function () use ($assignedUserId, $tenant) {
            $conversation = Conversation::create(['state' => [], 'mode' => 'suggest', 'assigned_user_id' => $assignedUserId]);
            $message = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Есть доставка?', 'meta' => []]);

            return app(ConversationRepository::class)->claimTurn($tenant, new TurnCommand($conversation->id, $message->id, $message->text, null, 'test-'.Str::random(24), 0));
        });
        $outcome = ['status' => 'suggested', 'turnId' => $claim->turnId, 'conversationId' => $claim->conversationId, 'version' => 1, 'messages' => [['text' => $text]]];
        DB::table('conversations')->where('id', $claim->conversationId)->update(['version' => 1]);
        DB::table('seller_turns')->where('id', $claim->turnId)->update(['status' => 'suggested', 'outcome' => json_encode($outcome), 'outcome_hash' => CanonicalJson::hash($outcome)]);

        return [$claim, $text];
    }

    public function test_send_as_is_marks_sent_and_delivers_message(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enableSeller($org, $brand);
        [$claim, $text] = $this->readySuggestion($org, $brand);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])
            ->postJson("/seller/dialogs/{$claim->conversationId}/suggestions/{$claim->turnId}/send", ['text' => $text])
            ->assertOk()->assertJsonPath('status', 'ok');
        $turn = DB::table('seller_turns')->where('id', $claim->turnId)->first();
        self::assertSame('sent', $turn->status);
        self::assertSame($owner->id, $turn->sent_by_user_id);
        $message = DB::table('messages')->where('conversation_id', $claim->conversationId)->where('role', 'manager')->first();
        self::assertSame($text, $message->text);
    }

    public function test_send_with_edit_marks_edited(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enableSeller($org, $brand);
        [$claim, $text] = $this->readySuggestion($org, $brand);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])
            ->postJson("/seller/dialogs/{$claim->conversationId}/suggestions/{$claim->turnId}/send", ['text' => $text.' Уточним детали.'])
            ->assertOk();
        self::assertSame('edited', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
    }

    public function test_skip_leaves_turn_unsent_and_is_idempotent(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enableSeller($org, $brand);
        [$claim] = $this->readySuggestion($org, $brand);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])
            ->postJson("/seller/dialogs/{$claim->conversationId}/suggestions/{$claim->turnId}/skip")
            ->assertOk()->assertJsonPath('status', 'ok');
        $turn = DB::table('seller_turns')->where('id', $claim->turnId)->first();
        self::assertSame('suggested', $turn->status);
        self::assertSame('skipped', json_decode($turn->outcome, true)['resolved']);
        self::assertSame(0, DB::table('messages')->where('conversation_id', $claim->conversationId)->where('role', 'manager')->count());
        $this->postJson("/seller/dialogs/{$claim->conversationId}/suggestions/{$claim->turnId}/skip")->assertOk();
        self::assertSame('skipped', json_decode(DB::table('seller_turns')->where('id', $claim->turnId)->value('outcome'), true)['resolved']);
        $this->getJson('/seller/dialogs/suggestions')->assertOk()->assertJsonCount(0);
    }

    public function test_mark_flags_without_sending(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enableSeller($org, $brand);
        [$claim] = $this->readySuggestion($org, $brand);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])
            ->postJson("/seller/dialogs/{$claim->conversationId}/suggestions/{$claim->turnId}/mark")
            ->assertOk();
        $turn = DB::table('seller_turns')->where('id', $claim->turnId)->first();
        self::assertSame('suggested', $turn->status);
        self::assertSame('flagged', json_decode($turn->outcome, true)['resolved']);
        $this->postJson("/seller/dialogs/{$claim->conversationId}/suggestions/{$claim->turnId}/send", ['text' => 'Что угодно'])
            ->assertStatus(422)->assertJsonValidationErrors('text');
    }

    public function test_sales_manager_is_limited_to_assigned_dialog(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enableSeller($org, $brand);
        [$manager] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        [$claim] = $this->readySuggestion($org, $brand, assignedUserId: $owner->id);
        $this->actingAs($manager)->withSession(['brand_id' => $brand->id])
            ->postJson("/seller/dialogs/{$claim->conversationId}/suggestions/{$claim->turnId}/skip")
            ->assertNotFound();
        self::assertSame('suggested', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
    }

    public function test_brand_isolation(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enableSeller($org, $brand);
        $this->enableSeller($org, $other);
        [$claim] = $this->readySuggestion($org, $brand);
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])
            ->postJson("/seller/dialogs/{$claim->conversationId}/suggestions/{$claim->turnId}/skip")
            ->assertNotFound();
        self::assertSame('suggested', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
    }

    public function test_send_validation_errors(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enableSeller($org, $brand);
        [$claim] = $this->readySuggestion($org, $brand);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->postJson("/seller/dialogs/{$claim->conversationId}/suggestions/{$claim->turnId}/send", ['text' => ''])->assertStatus(422);
        $this->postJson("/seller/dialogs/{$claim->conversationId}/suggestions/{$claim->turnId}/send", ['text' => str_repeat('a', 5001)])->assertStatus(422);
    }

    public function test_send_rejects_stale_outcome(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enableSeller($org, $brand);
        [$claim] = $this->readySuggestion($org, $brand);
        DB::table('seller_turns')->where('id', $claim->turnId)->update(['outcome_hash' => str_repeat('0', 64)]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])
            ->postJson("/seller/dialogs/{$claim->conversationId}/suggestions/{$claim->turnId}/send", ['text' => 'Что угодно'])
            ->assertStatus(422)->assertJsonValidationErrors('text');
    }

    public function test_suggestions_endpoint_lists_only_own_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enableSeller($org, $brand);
        $this->enableSeller($org, $other);
        [$claim, $text] = $this->readySuggestion($org, $brand);
        $this->readySuggestion($org, $other);
        $response = $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->getJson('/seller/dialogs/suggestions')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        self::assertCount(1, $response->json());
        self::assertSame($claim->turnId, $response->json()[0]['id']);
        self::assertSame($text, $response->json()[0]['suggestion']);
    }
}
