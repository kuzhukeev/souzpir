<?php

namespace Tests\Feature\Seller\Conversations;

use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ClaimHistorySnapshotTest extends TenancyTestCase
{
    public function test_claim_captures_current_history_and_keeps_its_identity_immutable(): void
    {
        [, $org, $brand] = $this->cabinet();
        [$conversation, $input] = $this->within($org, $brand, function (): array {
            $conversation = Conversation::create(['state' => []]);
            $input = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Synthetic input', 'meta' => []]);

            return [$conversation, $input];
        });
        $context = new TenantContext($org->id, $brand->id, source: 'job');
        $claim = app(ConversationRepository::class)->claimTurn($context, new TurnCommand($conversation->id, $input->id, $input->text, null, 'synthetic-claim', 0));
        $turn = fn () => DB::table('seller_turns')->where('id', $claim->turnId)->first();
        self::assertSame(1, $turn()->base_history_revision);
        self::assertSame(1, $turn()->cutoff_sequence);
        $this->within($org, $brand, fn () => Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Later synthetic input', 'meta' => []]));
        self::assertSame(2, DB::table('conversations')->where('id', $conversation->id)->value('history_revision'));
        self::assertSame(1, $turn()->base_history_revision);
        self::assertSame(1, $turn()->cutoff_sequence);

        foreach ([['base_history_revision' => 2], ['cutoff_sequence' => 2], ['claim_hash' => str_repeat('a', 64)], ['idempotency_key' => 'changed']] as $change) {
            try {
                DB::transaction(fn () => DB::table('seller_turns')->where('id', $claim->turnId)->update($change));
                self::fail('Claim identity was modified.');
            } catch (QueryException $error) {
                self::assertSame('23514', $error->errorInfo[0]);
            }
        }
    }

    public function test_claim_of_an_older_input_is_not_given_a_current_history_snapshot(): void
    {
        [, $org, $brand] = $this->cabinet();
        [$conversation, $first] = $this->within($org, $brand, function (): array {
            $conversation = Conversation::create(['state' => []]);
            $first = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'First', 'meta' => []]);
            Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Second', 'meta' => []]);

            return [$conversation, $first];
        });
        $context = new TenantContext($org->id, $brand->id, source: 'job');
        $claim = app(ConversationRepository::class)->claimTurn($context, new TurnCommand($conversation->id, $first->id, $first->text, null, 'old-input', 0));
        $turn = DB::table('seller_turns')->where('id', $claim->turnId)->first();
        self::assertNull($turn->base_history_revision);
        self::assertNull($turn->cutoff_sequence);
    }

    public function test_direct_insert_cannot_supply_a_forged_snapshot(): void
    {
        [, $org, $brand] = $this->cabinet();
        [$conversation, $input] = $this->within($org, $brand, function (): array {
            $conversation = Conversation::create(['state' => []]);
            $input = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Synthetic input', 'meta' => []]);

            return [$conversation, $input];
        });
        try {
            DB::transaction(fn () => DB::table('seller_turns')->insert([
                'id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
                'conversation_id' => $conversation->id, 'input_message_id' => $input->id,
                'idempotency_key' => 'forged', 'request_hash' => str_repeat('a', 64), 'claim_hash' => str_repeat('b', 64),
                'base_version' => 0, 'base_history_revision' => 100, 'cutoff_sequence' => 100,
            ]));
            self::fail('A caller-supplied snapshot was accepted.');
        } catch (QueryException $error) {
            self::assertSame('23514', $error->errorInfo[0]);
            self::assertSame(0, DB::table('seller_turns')->count());
        }
    }

    public function test_existing_turn_is_not_given_a_retroactive_snapshot(): void
    {
        [, $org, $brand] = $this->cabinet();
        $migration = require database_path('migrations/C-02/2026_11_01_010100_c02_capture_turn_history.php');
        $migration->down();
        [$conversation, $input] = $this->within($org, $brand, function (): array {
            $conversation = Conversation::create(['state' => []]);
            $input = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Existing synthetic input', 'meta' => []]);

            return [$conversation, $input];
        });
        $context = new TenantContext($org->id, $brand->id, source: 'job');
        $claim = app(ConversationRepository::class)->claimTurn($context, new TurnCommand($conversation->id, $input->id, $input->text, null, 'legacy-claim', 0));
        $migration->up();
        $turn = DB::table('seller_turns')->where('id', $claim->turnId)->first();
        self::assertNull($turn->base_history_revision);
        self::assertNull($turn->cutoff_sequence);
        try {
            DB::transaction(fn () => DB::table('seller_turns')->where('id', $claim->turnId)->update(['base_history_revision' => 1, 'cutoff_sequence' => 1]));
            self::fail('An existing claim was given fabricated history.');
        } catch (QueryException $error) {
            self::assertSame('23514', $error->errorInfo[0]);
        }
    }
}
