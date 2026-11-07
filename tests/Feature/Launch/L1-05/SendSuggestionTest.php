<?php

namespace Tests\Feature\Launch\L1_05;

use App\Seller\Conversations\CanonicalJson;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Seller\Dialogs\DeliverManagerMessage;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SendSuggestionTest extends TenancyTestCase
{
    private function readyDialog(object $org, object $brand, ?string $assignedUserId = null): array
    {
        if (! DB::table('brand_modules')->where('brand_id', $brand->id)->where('module', 'seller')->exists()) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $claim = app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $assignedUserId) {
            $conversation = Conversation::create(['state' => [], 'mode' => 'suggest', 'assigned_user_id' => $assignedUserId]);
            $message = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Сколько стоит?', 'meta' => []]);

            return app(ConversationRepository::class)->claimTurn($tenant, new TurnCommand($conversation->id, $message->id, $message->text, null, 'test-'.Str::random(24), 0));
        });
        $outcome = ['status' => 'suggested', 'turnId' => $claim->turnId, 'conversationId' => $claim->conversationId, 'version' => 1, 'messages' => [['text' => 'Доставка бесплатная по городу.']]];
        DB::table('conversations')->where('id', $claim->conversationId)->update(['version' => 1]);
        DB::table('seller_turns')->where('id', $claim->turnId)->update(['status' => 'suggested', 'outcome' => json_encode($outcome), 'outcome_hash' => CanonicalJson::hash($outcome)]);

        return ['conversation_id' => $claim->conversationId, 'turn_id' => $claim->turnId, 'suggestion' => 'Доставка бесплатная по городу.'];
    }

    public function test_owner_sends_draft_as_is_without_taking_the_dialog(): void
    {
        Queue::fake();
        [$owner, $org, $brand] = $this->cabinet();
        $draft = $this->readyDialog($org, $brand);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/dialogs/'.$draft['conversation_id'])
            ->assertOk()->assertInertia(fn ($page) => $page->where('readySuggestionTurnId', $draft['turn_id']));
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])
            ->post("/seller/dialogs/{$draft['conversation_id']}/suggestions/{$draft['turn_id']}/send", ['text' => $draft['suggestion']])
            ->assertRedirect('/seller/dialogs/'.$draft['conversation_id']);
        $turn = DB::table('seller_turns')->where('id', $draft['turn_id'])->first();
        $this->assertSame('sent', $turn->status);
        $this->assertSame($owner->id, $turn->sent_by_user_id);
        $this->assertNotNull($turn->sent_at);
        $message = DB::table('messages')->where('id', $turn->sent_message_id)->first();
        $this->assertSame('manager', $message->role);
        $this->assertSame($draft['suggestion'], $message->text);
        $conversation = DB::table('conversations')->where('id', $draft['conversation_id'])->first();
        $this->assertSame('suggest', $conversation->mode);
        Queue::assertPushed(DeliverManagerMessage::class, fn ($job) => $job->messageId === $message->id);
    }

    public function test_owner_edits_text_before_sending(): void
    {
        Queue::fake();
        [$owner, $org, $brand] = $this->cabinet();
        $draft = $this->readyDialog($org, $brand);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])
            ->post("/seller/dialogs/{$draft['conversation_id']}/suggestions/{$draft['turn_id']}/send", ['text' => 'Доставка бесплатная по городу, уточню про область.'])
            ->assertRedirect();
        $turn = DB::table('seller_turns')->where('id', $draft['turn_id'])->first();
        $this->assertSame('edited', $turn->status);
    }

    public function test_viewer_cannot_send(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $draft = $this->readyDialog($org, $brand);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])
            ->post("/seller/dialogs/{$draft['conversation_id']}/suggestions/{$draft['turn_id']}/send", ['text' => $draft['suggestion']])
            ->assertForbidden();
        $this->assertSame('suggested', DB::table('seller_turns')->where('id', $draft['turn_id'])->value('status'));
    }

    public function test_sales_manager_restricted_to_assigned_dialog(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        [$manager] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        $unassigned = $this->readyDialog($org, $brand);
        $this->actingAs($manager)->withSession(['brand_id' => $brand->id])
            ->post("/seller/dialogs/{$unassigned['conversation_id']}/suggestions/{$unassigned['turn_id']}/send", ['text' => $unassigned['suggestion']])
            ->assertNotFound();
        $assigned = $this->readyDialog($org, $brand, $manager->id);
        $this->actingAs($manager)->withSession(['brand_id' => $brand->id])
            ->post("/seller/dialogs/{$assigned['conversation_id']}/suggestions/{$assigned['turn_id']}/send", ['text' => $assigned['suggestion']])
            ->assertRedirect();
        $this->assertSame('sent', DB::table('seller_turns')->where('id', $assigned['turn_id'])->value('status'));
    }

    public function test_other_brand_cannot_see_or_send_foreign_draft(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $draft = $this->readyDialog($org, $brand);
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $other->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])
            ->post("/seller/dialogs/{$draft['conversation_id']}/suggestions/{$draft['turn_id']}/send", ['text' => $draft['suggestion']])
            ->assertNotFound();
    }

    public function test_stale_draft_is_rejected_after_new_client_message(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $draft = $this->readyDialog($org, $brand);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        app(TenantContextRunner::class)->run($tenant, function () use ($draft): void {
            Message::create(['conversation_id' => $draft['conversation_id'], 'role' => 'client', 'text' => 'Ещё вопрос', 'meta' => []]);
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])
            ->post("/seller/dialogs/{$draft['conversation_id']}/suggestions/{$draft['turn_id']}/send", ['text' => $draft['suggestion']])
            ->assertInvalid(['text' => 'Черновик устарел.']);
        $this->assertSame('suggested', DB::table('seller_turns')->where('id', $draft['turn_id'])->value('status'));
    }

    public function test_double_submit_sends_once(): void
    {
        Queue::fake();
        [$owner, $org, $brand] = $this->cabinet();
        $draft = $this->readyDialog($org, $brand);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])
            ->post("/seller/dialogs/{$draft['conversation_id']}/suggestions/{$draft['turn_id']}/send", ['text' => $draft['suggestion']])
            ->assertRedirect();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])
            ->post("/seller/dialogs/{$draft['conversation_id']}/suggestions/{$draft['turn_id']}/send", ['text' => $draft['suggestion']])
            ->assertRedirect();
        $this->assertSame(1, DB::table('messages')->where('conversation_id', $draft['conversation_id'])->where('role', 'manager')->count());
    }
}
