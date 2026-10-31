<?php

namespace Tests\Feature\Seller\Conversations;

use App\Seller\Channels\Core\ChannelAccount;
use App\Seller\Channels\Core\InboundRecorder;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Data\TurnOutcome;
use App\Seller\Conversations\Models\Contact;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Seller\Conversations\Models\SellerTask;
use App\Seller\Settings\BrandSellerSettings;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ConversationRepositoryTest extends TenancyTestCase
{
    private function setupConversation($org, $brand, ?ChannelAccount $channel = null): array
    {
        return $this->within($org, $brand, function () use ($channel): array {
            $conversation = Conversation::create(['channel_account_id' => $channel?->id, 'mode' => 'bot', 'state' => []]);
            $message = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Нужна доставка', 'meta' => []]);

            return [$conversation, $message];
        });
    }

    private function context($org, $brand): TenantContext
    {
        return new TenantContext($org->id, $brand->id, source: 'job');
    }

    public function test_defaults_and_credentials_are_safe_and_tenant_scoped(): void
    {
        [, $org, $a, $b] = $this->cabinet();
        [$channel, $settings] = $this->within($org, $a, function (): array {
            return [ChannelAccount::create(['type' => 'telegram', 'credentials' => ['bot_token' => 'sensitive-example'], 'managers_chat_id' => '987', 'settings' => ['locale' => 'ru']]), BrandSellerSettings::create([])];
        });
        $this->assertNotSame('sensitive-example', DB::table('channel_accounts')->where('id', $channel->id)->value('credentials'));
        $this->assertArrayNotHasKey('credentials', $channel->toArray());
        $this->assertArrayNotHasKey('managers_chat_id', $channel->toArray());
        $this->assertSame(['bot_token' => 'sensitive-example'], $channel->credentials);
        $this->assertSame(20, $settings->followup['first_hours']);
        $this->assertNull($settings->followup['second_hours']);
        $this->assertSame(2, $settings->followup['max_auto']);
        $this->assertSame(3, $settings->off_topic['streak']);
        $this->assertSame(2, $settings->negative['streak']);
        $this->assertFalse($settings->catalog_prices_enabled);
        $this->assertNull($this->within($org, $b, fn () => ChannelAccount::find($channel->id)));
        $this->assertNull(ChannelAccount::find($channel->id));
        try {
            $this->within($org, $a, fn () => ChannelAccount::create(['type' => 'api', 'settings' => ['nested' => ['api_key' => 'leak']]]));
            $this->fail('Raw credentials allowed');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
    }

    public function test_nullable_channel_contact_and_composite_tenant_foreign_keys(): void
    {
        [, $org, $a, $b] = $this->cabinet();
        [$plain, $input] = $this->setupConversation($org, $a);
        $this->assertNull($plain->channel_account_id);
        $this->assertNull($plain->contact_id);
        $otherContact = $this->within($org, $b, fn () => Contact::create(['name' => 'Foreign brand']));
        try {
            DB::transaction(fn () => $this->within($org, $a, fn () => Conversation::create(['contact_id' => $otherContact->id])));
            $this->fail('Cross-brand foreign key accepted');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        try {
            app(ConversationRepository::class)->find($this->context($org, $b), $plain->id);
            $this->fail('Foreign brand accepted');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
    }

    public function test_inbound_events_are_unique_per_channel_and_replay_checks_payload(): void
    {
        [, $org, $a, $b] = $this->cabinet();
        [$one, $two] = $this->within($org, $a, fn () => [ChannelAccount::create(['type' => 'api']), ChannelAccount::create(['type' => 'telegram'])]);
        $service = app(InboundRecorder::class);
        $context = $this->context($org, $a);
        $first = $service->record($context, $one->id, 'ext-1', ['data' => ['z' => 1, 'a' => 2]]);
        $replay = $service->record($context, $one->id, 'ext-1', ['data' => ['a' => 2, 'z' => 1]]);
        $this->assertSame($first->id, $replay->id);
        $second = $service->record($context, $two->id, 'ext-1', ['data' => ['a' => 2, 'z' => 1]]);
        $this->assertNotSame($first->id, $second->id);
        try {
            $service->record($context, $one->id, 'ext-1', ['data' => ['a' => 999]]);
            $this->fail('Conflicting event accepted');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        try {
            $service->record($this->context($org, $b), $one->id, 'ext-2', []);
            $this->fail('Foreign channel accepted');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
        $this->assertDatabaseCount('channel_inbound_events', 2);
    }

    public function test_claim_commit_idempotence_and_atomic_outbox(): void
    {
        Queue::fake();
        [, $org, $brand] = $this->cabinet();
        [$conversation, $input] = $this->setupConversation($org, $brand);
        $context = $this->context($org, $brand);
        $repo = app(ConversationRepository::class);
        $command = new TurnCommand($conversation->id, $input->id, $input->text, null, 'key-1', 0);
        $claim = $repo->claimTurn($context, $command);
        $this->assertSame('claimed', $claim->status);
        $this->assertSame('in_progress', $repo->claimTurn($context, $command)->status);
        $outputId = (string) Str::ulid();
        $taskId = (string) Str::ulid();
        $eventId = (string) Str::ulid();
        $event = ['event_id' => $eventId, 'schema_version' => 1, 'type' => 'seller.lead.created', 'occurred_at' => now()->toIso8601String(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'actor_id' => null, 'aggregate' => ['type' => 'conversation', 'id' => $conversation->id, 'version' => 1], 'data' => ['conversation_id' => $conversation->id]];
        $outcome = new TurnOutcome($claim->turnId, $conversation->id, 'completed', 1, $outputId, ['kind' => 'answer'], ['result' => 'ok'], [], ['stage' => 'open'], [['id' => $outputId, 'role' => 'agent', 'text' => 'Ответ', 'meta' => []]], [['id' => $taskId, 'type' => 'handoff', 'payload' => []]], [$event]);
        $repo->commitTurn($claim, $outcome);
        $repo->commitTurn($claim, $outcome);
        $this->assertSame('finished', $repo->claimTurn($context, $command)->status);
        $this->assertSame(1, $repo->find($context, $conversation->id)->version);
        $this->assertDatabaseCount('seller_turns', 1);
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseCount('seller_tasks', 1);
        $this->assertDatabaseCount('outbox_events', 1);
        $this->assertDatabaseHas('messages', ['id' => $outputId, 'role' => 'agent', 'delivered_at' => null]);
    }

    public function test_conflicting_request_active_claim_and_stale_version_fail_closed(): void
    {
        [, $org, $brand] = $this->cabinet();
        [$conversation, $input] = $this->setupConversation($org, $brand);
        $context = $this->context($org, $brand);
        $repo = app(ConversationRepository::class);
        $claim = $repo->claimTurn($context, new TurnCommand($conversation->id, $input->id, $input->text, null, 'key-1', 0));
        foreach ([new TurnCommand($conversation->id, $input->id, 'other', null, 'key-1', 0), new TurnCommand($conversation->id, $input->id, $input->text, null, 'key-2', 0)] as $command) {
            try {
                $repo->claimTurn($context, $command);
                $this->fail('Conflict accepted');
            } catch (HttpException $e) {
                $this->assertSame(409, $e->getStatusCode());
            }
        }
        $this->within($org, $brand, fn () => $conversation->update(['version' => 1]));
        $outcome = new TurnOutcome($claim->turnId, $conversation->id, 'completed', 1, null, null, null, [], []);
        try {
            $repo->commitTurn($claim, $outcome);
            $this->fail('Stale claim accepted');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseHas('seller_turns', ['id' => $claim->turnId, 'status' => 'processing']);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_message_cannot_be_reprocessed_with_a_fresh_key_after_completion(): void
    {
        [, $org, $brand] = $this->cabinet();
        [$conversation, $input] = $this->setupConversation($org, $brand);
        $context = $this->context($org, $brand);
        $repo = app(ConversationRepository::class);
        $claim = $repo->claimTurn($context, new TurnCommand($conversation->id, $input->id, $input->text, null, 'first', 0));
        $repo->commitTurn($claim, new TurnOutcome($claim->turnId, $conversation->id, 'completed', 1, null, null, null, [], []));
        try {
            $repo->claimTurn($context, new TurnCommand($conversation->id, $input->id, $input->text, null, 'different-key', 1));
            $this->fail('Input processed twice');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseCount('seller_turns', 1);
        $this->assertStringNotContainsString($claim->token(), print_r($claim, true));
    }

    public function test_bad_outcome_rolls_back_messages_and_keeps_claim_unfinished(): void
    {
        [, $org, $brand] = $this->cabinet();
        [$conversation, $input] = $this->setupConversation($org, $brand);
        $repo = app(ConversationRepository::class);
        $claim = $repo->claimTurn($this->context($org, $brand), new TurnCommand($conversation->id, $input->id, $input->text, null, 'rollback', 0));
        $outcome = new TurnOutcome($claim->turnId, $conversation->id, 'completed', 1, (string) Str::ulid(), null, null, [], [], [['id' => (string) Str::ulid(), 'role' => 'agent', 'text' => 'Should roll back']]);
        try {
            $repo->commitTurn($claim, $outcome);
            $this->fail('Invalid outcome accepted');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('outbox_events', 0);
        $this->assertDatabaseHas('seller_turns', ['id' => $claim->turnId, 'status' => 'processing']);
        $this->assertSame(0, $repo->find($this->context($org, $brand), $conversation->id)->version);
    }

    public function test_assignees_must_be_authorized_members_of_the_brand(): void
    {
        [, $org, $brand, $otherBrand] = $this->cabinet();
        [$conversation] = $this->setupConversation($org, $brand);
        [$outsider] = $this->member($org, 'viewer', [$otherBrand->id]);
        try {
            $this->within($org, $brand, fn () => SellerTask::create(['conversation_id' => $conversation->id, 'type' => 'handoff', 'assigned_user_id' => $outsider->id]));
            $this->fail('Unauthorized assignee accepted');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
        $other = $this->user();
        try {
            DB::transaction(fn () => DB::table('seller_tasks')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $conversation->id, 'type' => 'handoff', 'assigned_user_id' => $other->id]));
            $this->fail('Non-member accepted by database');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_claim_secret_cannot_be_serialized_or_unserialized(): void
    {
        [, $org, $brand] = $this->cabinet();
        [$conversation, $input] = $this->setupConversation($org, $brand);
        $claim = app(ConversationRepository::class)->claimTurn($this->context($org, $brand), new TurnCommand($conversation->id, $input->id, $input->text, null, 'serialization', 0));
        try {
            serialize($claim);
            $this->fail('Claim token was serializable');
        } catch (\LogicException $e) {
            $this->assertStringNotContainsString($claim->token(), $e->getMessage());
        }
        $class = TurnClaim::class;
        try {
            unserialize('O:'.strlen($class).':"'.$class.'":0:{}');
            $this->fail('Claim could be restored');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
    }

    public function test_inbound_event_unique_constraint_and_exact_replay(): void
    {
        [, $org, $brand] = $this->cabinet();
        $channel = $this->within($org, $brand, fn () => ChannelAccount::create(['type' => 'api']));
        [$a, $aMessage] = $this->setupConversation($org, $brand, $channel);
        [$b, $bMessage] = $this->setupConversation($org, $brand, $channel);
        $context = $this->context($org, $brand);
        $recorder = app(InboundRecorder::class);
        $repo = app(ConversationRepository::class);
        $first = $recorder->record($context, $channel->id, 'first', []);
        $second = $recorder->record($context, $channel->id, 'second', []);
        $command = new TurnCommand($a->id, $aMessage->id, $aMessage->text, $first->id, 'a-key', 0);
        $repo->claimTurn($context, $command);
        $this->assertSame('in_progress', $repo->claimTurn($context, $command)->status);
        $bClaim = $repo->claimTurn($context, new TurnCommand($b->id, $bMessage->id, $bMessage->text, $second->id, 'b-key', 0));
        try {
            DB::transaction(fn () => DB::table('seller_turns')->where('id', $bClaim->turnId)->update(['external_event_id' => $first->id]));
            $this->fail('Database accepted duplicate inbound event');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }
}
