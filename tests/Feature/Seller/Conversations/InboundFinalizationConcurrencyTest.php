<?php

namespace Tests\Feature\Seller\Conversations;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\ProtectedOperations\SellerTurnFence;
use App\Seller\Channels\Core\ChannelAccount;
use App\Seller\Channels\Core\InboundRecorder;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Data\TurnOutcome;
use App\Seller\Conversations\Models\Conversation;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('race')]
final class InboundFinalizationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function createApplication()
    {
        $app = parent::createApplication();
        $db = DB::selectOne('select current_database() as name, host(inet_server_addr()) as host, rolsuper, rolcreatedb, rolcreaterole from pg_roles where rolname = current_user');
        $expected = env('DB_TEST_DATABASE');
        if (! $app->environment('testing') || ! is_string($expected) || ! str_contains($expected, '_test_')
            || env('APP_PREVIEW_DATABASE') === $expected || config('database.connections.pgsql.database') !== $expected
            || $db->name !== $expected || (! str_starts_with((string) $db->host, '127.') && $db->host !== '::1')
            || $db->rolsuper || $db->rolcreatedb || $db->rolcreaterole) {
            throw new \RuntimeException('An isolated test database is required.');
        }

        return $app;
    }

    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(static function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        Queue::fake();
    }

    public function test_inbound_first_preserves_message_and_quarantines_stale_finalization(): void
    {
        [$org, $brand, $conversation, $channel, $context, $input, $command, $claim] = $this->fixture();
        $agent = (string) Str::ulid();
        $marker = base_path('.local/tmp/c02-finalize-'.Str::random(16));
        $worker = $this->worker('commit', $marker, $org, $brand, $conversation, $channel, $claim, $agent);
        DB::beginTransaction();
        try {
            $new = app(InboundRecorder::class)->ingest($context, $channel->id, 'second-synthetic-event', [], $conversation->id, 'Second synthetic input');
            $worker->start();
            $this->assertWorkerWaiting($worker, $marker);
            DB::commit();
            $worker->wait();
            self::assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            self::assertSame('rejected', trim($worker->getOutput()));
            self::assertSame('uncertain', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
            self::assertSame(0, DB::table('seller_turns')->where('conversation_id', $conversation->id)->where('status', 'processing')->count());
            self::assertSame(2, DB::table('messages')->where('conversation_id', $conversation->id)->count());
            self::assertSame('Second synthetic input', DB::table('messages')->where('id', $new->id)->value('text'));
            self::assertSame(0, DB::table('messages')->where('id', $agent)->count());
            self::assertSame(0, DB::table('conversations')->where('id', $conversation->id)->value('version'));
            self::assertSame(0, DB::table('seller_tasks')->count());
            self::assertSame(0, DB::table('outbox_events')->count());
            $replay = app(ConversationRepository::class)->claimTurn($context, $command);
            self::assertSame('uncertain', $replay->status);
            self::assertNull($replay->outcome);
            self::assertNull($replay->token());
            try {
                app(ConversationRepository::class)->claimTurn($context, new TurnCommand($conversation->id, $new->id, $new->text, $new->external_event_id, 'new-turn', 0));
                self::fail('An unresolved effect must block a new turn.');
            } catch (HttpExceptionInterface $error) {
                self::assertSame(409, $error->getStatusCode());
            }
            try {
                DB::transaction(fn () => DB::table('seller_turns')->insert([
                    'id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
                    'conversation_id' => $conversation->id, 'input_message_id' => $new->id,
                    'external_event_id' => $new->external_event_id, 'idempotency_key' => 'raw-new-turn',
                    'request_hash' => str_repeat('a', 64), 'claim_hash' => str_repeat('b', 64),
                    'base_version' => 0, 'status' => 'processing', 'created_at' => now(), 'updated_at' => now(),
                ]));
                self::fail('The database must also block a parallel turn.');
            } catch (QueryException $error) {
                self::assertSame('23505', $error->errorInfo[0]);
            }
            try {
                DB::transaction(fn () => DB::table('seller_turns')->where('id', $claim->turnId)->update(['status' => 'failed']));
                self::fail('Manual recovery has not been implemented.');
            } catch (QueryException $error) {
                self::assertSame('23514', $error->errorInfo[0]);
            }
            try {
                DB::transaction(fn () => DB::statement('TRUNCATE TABLE seller_turns CASCADE'));
                self::fail('Uncertain history cannot be truncated.');
            } catch (QueryException $error) {
                self::assertSame('23514', $error->errorInfo[0]);
            }
            try {
                DB::transaction(fn () => DB::table('seller_turns')->where('id', $claim->turnId)->delete());
                self::fail('Uncertain history cannot be removed.');
            } catch (QueryException $error) {
                self::assertSame('23514', $error->errorInfo[0]);
            }
            Http::assertNothingSent();
            Mail::assertNothingOutgoing();
            Queue::assertNothingPushed();
        } finally {
            $this->cleanup($worker, $marker);
        }
    }

    public function test_duplicate_and_rolled_back_inbound_do_not_cancel_a_valid_turn(): void
    {
        [, , $conversation, $channel, $context, $input, , $claim] = $this->fixture();
        $duplicate = app(InboundRecorder::class)->ingest($context, $channel->id, 'first-synthetic-event', [], $conversation->id, 'First synthetic input');
        self::assertSame($input->id, $duplicate->id);
        self::assertSame('processing', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
        DB::beginTransaction();
        try {
            app(InboundRecorder::class)->ingest($context, $channel->id, 'rolled-back-event', [], $conversation->id, 'Rolled back input');
            self::assertSame('uncertain', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
        } finally {
            DB::rollBack();
        }
        self::assertSame('processing', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
        self::assertSame(1, DB::table('messages')->where('conversation_id', $conversation->id)->count());
        self::assertSame(0, DB::table('channel_inbound_events')->where('external_id', 'rolled-back-event')->count());
        DB::transaction(fn () => app(SellerTurnFence::class)->assertCurrent($context, $claim));
        self::assertSame('processing', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_upgrade_quarantines_stale_processing_without_inventing_an_outcome(): void
    {
        $migration = require database_path('migrations/C-02/2026_11_01_040000_c02_uncertain_turn_on_inbound.php');
        $migration->down();
        [, , $conversation, $channel, $context, , $command, $claim] = $this->fixture();
        app(InboundRecorder::class)->ingest($context, $channel->id, 'legacy-later-event', [], $conversation->id, 'Legacy later input');
        self::assertSame('processing', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
        $migration->up();
        $turn = DB::table('seller_turns')->where('id', $claim->turnId)->first();
        self::assertSame('uncertain', $turn->status);
        self::assertNull($turn->outcome);
        self::assertNull($turn->outcome_hash);
        self::assertSame('uncertain', app(ConversationRepository::class)->claimTurn($context, $command)->status);
        try {
            $migration->down();
            self::fail('Rollback cannot discard unknown turns.');
        } catch (\RuntimeException) {
            self::assertTrue(true);
        }
        Http::assertNothingSent();
    }

    public function test_other_stale_history_is_quarantined_before_any_message_or_state_write(): void
    {
        [, , $conversation, , $context, , $command, $claim] = $this->fixture();
        DB::table('conversations')->where('id', $conversation->id)->update(['state' => json_encode(['later' => true], JSON_THROW_ON_ERROR)]);
        $agent = (string) Str::ulid();
        $outcome = new TurnOutcome($claim->turnId, $conversation->id, 'completed', 1, $agent, null, null, [],
            ['obsolete' => true], [['id' => $agent, 'role' => 'agent', 'text' => 'Obsolete synthetic output']]);
        try {
            app(ConversationRepository::class)->commitTurn($claim, $outcome);
            self::fail('Stale history must be rejected.');
        } catch (HttpExceptionInterface $error) {
            self::assertSame(409, $error->getStatusCode());
        }
        self::assertSame('uncertain', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
        self::assertSame('uncertain', app(ConversationRepository::class)->claimTurn($context, $command)->status);
        self::assertSame(0, DB::table('messages')->where('id', $agent)->count());
        self::assertSame(['later' => true], json_decode(DB::table('conversations')->where('id', $conversation->id)->value('state'), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(0, DB::table('conversations')->where('id', $conversation->id)->value('version'));
        Http::assertNothingSent();
    }

    public function test_upgrade_rejects_inconsistent_processing_without_discarding_its_outcome(): void
    {
        $migration = require database_path('migrations/C-02/2026_11_01_040000_c02_uncertain_turn_on_inbound.php');
        $migration->down();
        [, , , , , , , $claim] = $this->fixture();
        DB::table('seller_turns')->where('id', $claim->turnId)->update(['outcome' => '{"legacy":"keep"}']);
        try {
            DB::transaction(fn () => $migration->up());
            self::fail('Inconsistent pre-existing outcome requires investigation.');
        } catch (QueryException $error) {
            self::assertSame('23514', $error->errorInfo[0]);
        }
        $turn = DB::table('seller_turns')->where('id', $claim->turnId)->first();
        self::assertSame('processing', $turn->status);
        self::assertSame(['legacy' => 'keep'], json_decode($turn->outcome, true, 512, JSON_THROW_ON_ERROR));
        Http::assertNothingSent();
    }

    public function test_unverified_outcome_is_rejected_before_later_input(): void
    {
        [$org, $brand, $conversation, $channel, $context, $input, $command, $claim] = $this->fixture();
        $agent = (string) Str::ulid();
        $outcome = new TurnOutcome($claim->turnId, $conversation->id, 'completed', 1, $agent, null, null, [],
            ['synthetic' => true], [['id' => $agent, 'role' => 'agent', 'text' => 'Synthetic response']]);
        try {
            app(ConversationRepository::class)->commitTurn($claim, $outcome);
            self::fail('Unverified finalization was accepted.');
        } catch (\LogicException) {
            self::assertTrue(true);
        }
        $new = app(InboundRecorder::class)->ingest($context, $channel->id, 'second-synthetic-event', [], $conversation->id, 'Second synthetic input');
        self::assertSame('uncertain', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
        self::assertSame(2, DB::table('messages')->where('conversation_id', $conversation->id)->count());
        self::assertSame(0, DB::table('messages')->where('id', $agent)->count());
        self::assertSame('Second synthetic input', DB::table('messages')->where('id', $new->id)->value('text'));
        self::assertSame(0, DB::table('conversations')->where('id', $conversation->id)->value('version'));
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    private function fixture(): array
    {
        $owner = User::create(['name' => 'Synthetic owner', 'email' => Str::random(20).'@example.test', 'password' => 'test-only']);
        $org = Organization::create(['name' => 'Synthetic organization', 'owner_user_id' => $owner->id]);
        [$brand, $conversation, $channel] = app(TenantContextRunner::class)->run(new TenantContext($org->id, source: 'job'), function () use ($owner, $org): array {
            Membership::create(['user_id' => $owner->id, 'role' => 'owner']);
            $brand = Brand::create(['name' => 'Synthetic brand', 'theme' => 'pyro']);

            return app(TenantContextRunner::class)->run(new TenantContext($org->id, $brand->id, source: 'job'), function () use ($brand): array {
                $channel = ChannelAccount::create(['type' => 'api']);

                return [$brand, Conversation::create(['state' => [], 'channel_account_id' => $channel->id]), $channel];
            });
        });
        $context = new TenantContext($org->id, $brand->id, source: 'job');
        $input = app(InboundRecorder::class)->ingest($context, $channel->id, 'first-synthetic-event', [], $conversation->id, 'First synthetic input');
        $command = new TurnCommand($conversation->id, $input->id, $input->text, $input->external_event_id, 'first-synthetic', 0);
        $claim = app(ConversationRepository::class)->claimTurn($context, $command);

        return [$org, $brand, $conversation, $channel, $context, $input, $command, $claim];
    }

    private function worker(string $operation, string $marker, $org, $brand, $conversation, $channel, $claim, string $agent): Process
    {
        $worker = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), '-d', 'allow_url_fopen=0',
            '-d', 'disable_functions=fsockopen,pfsockopen,stream_socket_client,curl_exec,curl_multi_exec',
            base_path('tests/Feature/Seller/Conversations/Fixtures/inbound-finalization-worker.php'), $operation, $marker], base_path());
        $worker->setInput(json_encode(['organization' => $org->id, 'brand' => $brand->id, 'conversation' => $conversation->id,
            'channel' => $channel->id, 'turn' => $claim->turnId, 'token' => $claim->token(), 'agent' => $agent], JSON_THROW_ON_ERROR));
        $worker->setTimeout(15);

        return $worker;
    }

    private function assertWorkerWaiting(Process $worker, string $marker): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($marker) && $worker->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        self::assertFileExists($marker);
        $pid = (int) file_get_contents($marker);
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $waiting = DB::table('pg_stat_activity')->where('pid', $pid)->where('wait_event_type', 'Lock')->exists();
            if (! $waiting) {
                usleep(10000);
            }
        } while (! $waiting && $worker->isRunning() && microtime(true) < $deadline);
        self::assertTrue($waiting, 'Worker must wait for the opposing transaction.');
    }

    private function cleanup(Process $worker, string $marker): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if ($worker->isRunning()) {
            $worker->stop();
        }
        if (is_file($marker)) {
            unlink($marker);
        }
    }
}
