<?php

namespace Tests\Feature\ProtectedOperations;

use App\ProtectedOperations\SellerTurnFence;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

final class SellerTurnFenceTest extends TestCase
{
    use DatabaseMigrations;

    public function createApplication()
    {
        $app = parent::createApplication();
        $expected = env('DB_TEST_DATABASE');
        if (! $app->environment('testing') || ! is_string($expected) || ! str_contains($expected, '_test_')
            || DB::selectOne('select current_database() as name')->name !== $expected
            || config('database.connections.pgsql.database') !== $expected || env('APP_PREVIEW_DATABASE') === $expected) {
            throw new \RuntimeException('An isolated test database is required.');
        }

        return $app;
    }

    public function runDatabaseMigrations(): void
    {
        // Some production migrations intentionally cannot be rolled back.
        // Each test starts from a fresh, separately verified test database.
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(static function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_current_claim_is_checked_under_an_existing_transaction(): void
    {
        [$context, $claim] = $this->fixture();
        try {
            app(SellerTurnFence::class)->assertCurrent($context, $claim);
            self::fail('A released lock cannot protect a subsequent write.');
        } catch (LogicException $error) {
            self::assertSame('Seller turn fence rejected.', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
        DB::transaction(fn () => app(SellerTurnFence::class)->assertCurrent($context, $claim));
        self::assertSame('processing', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
    }

    public function test_forged_claim_token_context_and_version_are_rejected_without_mutation(): void
    {
        [$context, $claim] = $this->fixture();
        [$foreign, $foreignClaim] = $this->fixture();
        $variants = [
            [$context, new TurnClaim('claimed', $claim->turnId, $claim->conversationId, $context->organizationId, $context->brandId, $claim->baseVersion, str_repeat('a', 64))],
            [$foreign, $claim],
            [$foreign, new TurnClaim('claimed', $claim->turnId, $claim->conversationId, $foreign->organizationId, $foreign->brandId, $claim->baseVersion, $claim->token())],
            [$context, new TurnClaim('claimed', $foreignClaim->turnId, $claim->conversationId, $context->organizationId, $context->brandId, $claim->baseVersion, $claim->token())],
            [$context, new TurnClaim('claimed', $claim->turnId, $claim->conversationId, $context->organizationId, $context->brandId, $claim->baseVersion + 1, $claim->token())],
            [$context, new TurnClaim('finished', $claim->turnId, $claim->conversationId, $context->organizationId, $context->brandId, $claim->baseVersion, $claim->token())],
        ];
        foreach ($variants as [$tenant, $fake]) {
            try {
                DB::transaction(fn () => app(SellerTurnFence::class)->assertCurrent($tenant, $fake));
                self::fail('A forged fence was accepted.');
            } catch (LogicException $error) {
                self::assertSame('Seller turn fence rejected.', $error->getMessage());
                self::assertStringNotContainsString((string) $fake->token(), $error->getTraceAsString());
                self::assertNull($error->getPrevious());
            }
        }
        self::assertSame('processing', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
        self::assertSame(1, (int) DB::table('conversations')->where('id', $claim->conversationId)->value('history_revision'));
    }

    public function test_new_message_or_changed_state_rejects_stale_snapshot_without_losing_input(): void
    {
        [$context, $claim] = $this->fixture();
        app(TenantContextRunner::class)->run($context, fn () => Message::create(['conversation_id' => $claim->conversationId, 'role' => 'client', 'text' => 'Later synthetic message', 'meta' => []]));
        $this->assertRejected($context, $claim);
        self::assertSame(2, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
        self::assertSame(2, (int) DB::table('conversations')->where('id', $claim->conversationId)->value('history_revision'));

        [$otherContext, $otherClaim] = $this->fixture();
        DB::table('conversations')->where('id', $otherClaim->conversationId)->update(['mode' => 'manager']);
        $this->assertRejected($otherContext, $otherClaim);
        self::assertSame('manager', DB::table('conversations')->where('id', $otherClaim->conversationId)->value('mode'));
    }

    public function test_legacy_claim_without_verified_history_snapshot_is_rejected(): void
    {
        [$context, $claim] = $this->fixture(staleInput: true);
        self::assertNull(DB::table('seller_turns')->where('id', $claim->turnId)->value('base_history_revision'));
        $this->assertRejected($context, $claim);
        self::assertSame('processing', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
    }

    public function test_a_finished_turn_never_reuses_its_old_fence(): void
    {
        [$context, $claim] = $this->fixture();
        DB::table('seller_turns')->where('id', $claim->turnId)->update(['status' => 'completed']);
        $this->assertRejected($context, $claim);
        self::assertSame('completed', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
    }

    public function test_parent_lock_is_held_until_the_caller_transaction_finishes(): void
    {
        [$context, $claim] = $this->fixture();
        config(['database.connections.c02_fence_competitor' => config('database.connections.pgsql')]);
        $other = DB::connection('c02_fence_competitor');
        $message = ['id' => (string) Str::ulid(), 'organization_id' => $context->organizationId, 'brand_id' => $context->brandId,
            'conversation_id' => $claim->conversationId, 'role' => 'client', 'text' => 'Concurrent synthetic input', 'meta' => '{}'];
        DB::transaction(function () use ($context, $claim, $other, $message): void {
            app(SellerTurnFence::class)->assertCurrent($context, $claim);
            $other->beginTransaction();
            try {
                $other->statement("SET LOCAL lock_timeout = '250ms'");
                $other->table('messages')->insert($message);
                self::fail('Another connection inserted into a locked conversation.');
            } catch (QueryException $error) {
                self::assertSame('55P03', $error->errorInfo[0]);
            } finally {
                $other->rollBack();
            }
        });
        $other->table('messages')->insert($message);
        self::assertSame(2, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
        $this->assertRejected($context, $claim);
        DB::purge('c02_fence_competitor');
    }

    private function fixture(bool $staleInput = false): array
    {
        $org = (string) Str::ulid();
        $brand = (string) Str::ulid();
        DB::table('organizations')->insert(['id' => $org, 'name' => 'Synthetic', 'status' => 'active', 'settings' => '{}']);
        DB::table('brands')->insert(['id' => $brand, 'organization_id' => $org, 'name' => 'Synthetic', 'status' => 'active', 'theme' => 'neutral', 'settings' => '{}']);
        $context = new TenantContext($org, $brand, source: 'job');
        [$conversation, $input] = app(TenantContextRunner::class)->run($context, function () use ($staleInput): array {
            $conversation = Conversation::create(['state' => []]);
            $input = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Synthetic input', 'meta' => []]);
            if ($staleInput) {
                Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Later input', 'meta' => []]);
            }

            return [$conversation, $input];
        });
        $claim = app(ConversationRepository::class)->claimTurn($context, new TurnCommand($conversation->id, $input->id, $input->text, null, 'fence-test-'.Str::random(16), 0));

        return [$context, $claim];
    }

    private function assertRejected(TenantContext $context, TurnClaim $claim): void
    {
        try {
            DB::transaction(fn () => app(SellerTurnFence::class)->assertCurrent($context, $claim));
            self::fail('An obsolete or unverified fence was accepted.');
        } catch (LogicException $error) {
            self::assertSame('Seller turn fence rejected.', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }
}
