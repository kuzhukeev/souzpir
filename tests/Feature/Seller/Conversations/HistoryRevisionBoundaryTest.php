<?php

namespace Tests\Feature\Seller\Conversations;

use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Tenancy\TenancyTestCase;

final class HistoryRevisionBoundaryTest extends TenancyTestCase
{
    public function test_message_and_state_changes_advance_a_transactional_revision(): void
    {
        [, $org, $brand] = $this->cabinet();
        [$conversation, $message] = $this->within($org, $brand, function (): array {
            $conversation = Conversation::create(['state' => []]);
            $message = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Synthetic input', 'meta' => []]);

            return [$conversation, $message];
        });
        $row = fn () => DB::table('conversations')->where('id', $conversation->id)->first();
        self::assertTrue((bool) $row()->history_ready);
        self::assertSame(1, $row()->history_revision);
        self::assertSame(1, $row()->history_sequence);
        self::assertSame(1, DB::table('messages')->where('id', $message->id)->value('history_sequence'));

        DB::table('conversations')->where('id', $conversation->id)->update(['state' => '{"marker":"synthetic"}']);
        self::assertSame(2, $row()->history_revision);
        DB::table('messages')->where('id', $message->id)->update(['text' => 'Changed synthetic input']);
        self::assertSame(3, $row()->history_revision);
        DB::table('messages')->where('id', $message->id)->delete();
        self::assertSame(4, $row()->history_revision);
        self::assertSame(1, $row()->history_sequence);
        $migration = require database_path('migrations/C-02/2026_11_01_010000_c02_add_conversation_history_revision.php');
        try {
            DB::transaction(fn () => $migration->down());
            self::fail('Revision must survive even after the last message is deleted.');
        } catch (\RuntimeException $error) {
            self::assertSame('Cannot discard recorded history ordering.', $error->getMessage());
        }
        try {
            DB::transaction(function () use ($conversation): void {
                DB::table('conversations')->where('id', $conversation->id)->update(['mode' => 'manager']);
                throw new \LogicException('Synthetic rollback');
            });
        } catch (\LogicException) {
            self::assertSame(4, $row()->history_revision);
            self::assertSame('bot', $row()->mode);
        }
    }

    public function test_direct_counter_changes_and_history_truncation_fail_closed(): void
    {
        [, $org, $brand] = $this->cabinet();
        $conversation = $this->within($org, $brand, fn () => Conversation::create(['state' => []]));
        foreach ([
            fn () => DB::table('conversations')->where('id', $conversation->id)->update(['history_revision' => 2]),
            fn () => DB::table('conversations')->where('id', $conversation->id)->update(['history_sequence' => 2]),
            fn () => DB::table('conversations')->where('id', $conversation->id)->update(['history_ready' => false]),
            fn () => DB::statement('TRUNCATE TABLE messages CASCADE'),
        ] as $attempt) {
            try {
                DB::transaction($attempt);
                self::fail('History fence bypass accepted.');
            } catch (QueryException $error) {
                self::assertSame('23514', $error->errorInfo[0]);
            }
        }
        self::assertSame(0, DB::table('conversations')->where('id', $conversation->id)->value('history_revision'));
    }

    public function test_upgrade_keeps_existing_histories_unverified_and_preserves_input(): void
    {
        [, $org, $brand] = $this->cabinet();
        $migration = require database_path('migrations/C-02/2026_11_01_010000_c02_add_conversation_history_revision.php');
        $migration->down();
        [$conversation, $message] = $this->within($org, $brand, function (): array {
            $conversation = Conversation::create(['state' => []]);
            $message = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Existing synthetic input', 'meta' => []]);

            return [$conversation, $message];
        });
        $migration->up();
        $row = DB::table('conversations')->where('id', $conversation->id)->first();
        self::assertFalse((bool) $row->history_ready);
        self::assertSame(0, $row->history_revision);
        self::assertNull(DB::table('messages')->where('id', $message->id)->value('history_sequence'));
        self::assertSame('Existing synthetic input', DB::table('messages')->where('id', $message->id)->value('text'));
        $newMessage = $this->within($org, $brand, fn () => Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'New synthetic input', 'meta' => []]));
        self::assertSame(1, DB::table('messages')->where('id', $newMessage->id)->value('history_sequence'));
        self::assertFalse((bool) DB::table('conversations')->where('id', $conversation->id)->value('history_ready'));
        try {
            DB::transaction(fn () => DB::table('conversations')->where('id', $conversation->id)->update(['history_ready' => true]));
            self::fail('Unverified legacy history was marked ready.');
        } catch (QueryException $error) {
            self::assertSame('23514', $error->errorInfo[0]);
        }
        try {
            DB::transaction(fn () => $migration->down());
            self::fail('Recorded history ordering was discarded.');
        } catch (\RuntimeException $error) {
            self::assertSame('Cannot discard recorded history ordering.', $error->getMessage());
        }
    }

    public function test_revision_changes_are_scoped_to_the_correct_conversation_and_brand(): void
    {
        [, $org, $brand, $otherBrand] = $this->cabinet();
        $first = $this->within($org, $brand, fn () => Conversation::create(['state' => []]));
        $second = $this->within($org, $otherBrand, fn () => Conversation::create(['state' => []]));
        $this->within($org, $brand, fn () => Message::create(['conversation_id' => $first->id, 'role' => 'client', 'text' => 'Synthetic input', 'meta' => []]));
        self::assertSame(1, DB::table('conversations')->where('id', $first->id)->value('history_revision'));
        self::assertSame(0, DB::table('conversations')->where('id', $second->id)->value('history_revision'));
        self::assertSame(0, DB::table('conversations')->where('id', $second->id)->value('history_sequence'));
    }
}
