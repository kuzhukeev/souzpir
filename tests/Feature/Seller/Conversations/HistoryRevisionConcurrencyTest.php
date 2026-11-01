<?php

namespace Tests\Feature\Seller\Conversations;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Seller\Channels\Core\ChannelAccount;
use App\Seller\Channels\Core\InboundRecorder;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Models\Conversation;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('race')]
final class HistoryRevisionConcurrencyTest extends TestCase
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
        // Some production migrations intentionally cannot be rolled back. Each
        // test starts from a fresh dedicated test DB; never disable their guards.
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(static function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_inbound_waits_for_parent_and_is_saved_once(): void
    {
        [$org, $brand, $conversation, $channel] = $this->fixture();
        $marker = base_path('.local/tmp/c02-inbound-'.Str::random(16));
        $process = $this->worker('inbound', $org, $brand, $conversation, $channel, $marker);
        DB::beginTransaction();
        try {
            DB::table('conversations')->where('id', $conversation->id)->lockForUpdate()->first();
            $process->start();
            $this->waitForMarker($marker);
            $pid = (int) file_get_contents($marker);
            $deadline = microtime(true) + 10;
            do {
                DB::select('select pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->where('pid', $pid)->where('wait_event_type', 'Lock')->exists();
                if (! $waiting) {
                    usleep(10000);
                }
            } while (! $waiting && $process->isRunning() && microtime(true) < $deadline);
            self::assertTrue($waiting, 'Inbound must wait for the conversation lock.');
            DB::commit();
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            self::assertSame('inserted', trim($process->getOutput()));
            self::assertSame(1, DB::table('messages')->where('conversation_id', $conversation->id)->count());
            self::assertSame(1, DB::table('channel_inbound_events')->where('channel_account_id', $channel->id)->count());
            self::assertSame(1, DB::table('conversations')->where('id', $conversation->id)->value('history_revision'));
            self::assertSame(1, DB::table('messages')->where('conversation_id', $conversation->id)->value('history_sequence'));
            $retry = $this->worker('inbound', $org, $brand, $conversation, $channel, $marker);
            $retry->run();
            self::assertTrue($retry->isSuccessful(), $retry->getErrorOutput());
            self::assertSame('inserted', trim($retry->getOutput()));
            self::assertSame(1, DB::table('messages')->where('conversation_id', $conversation->id)->count());
            self::assertSame(1, DB::table('conversations')->where('id', $conversation->id)->value('history_revision'));
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($process->isRunning()) {
                $process->stop();
            }
            if (is_file($marker)) {
                unlink($marker);
            }
        }
    }

    public function test_unordered_direct_writer_fails_and_whole_transaction_can_be_retried(): void
    {
        [$org, $brand, $conversation, $channel] = $this->fixture();
        $marker = base_path('.local/tmp/c02-direct-'.Str::random(16));
        $process = $this->worker('direct', $org, $brand, $conversation, $channel, $marker);
        DB::beginTransaction();
        try {
            DB::table('conversations')->where('id', $conversation->id)->lockForUpdate()->first();
            $process->start();
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            self::assertSame('retry_required', trim($process->getOutput()));
            self::assertSame(0, DB::table('messages')->where('conversation_id', $conversation->id)->count());
            DB::commit();
            $retry = $this->worker('direct', $org, $brand, $conversation, $channel, $marker);
            $retry->run();
            self::assertTrue($retry->isSuccessful(), $retry->getErrorOutput());
            self::assertSame('inserted', trim($retry->getOutput()));
            self::assertSame(1, DB::table('messages')->where('conversation_id', $conversation->id)->count());
            self::assertSame(1, DB::table('conversations')->where('id', $conversation->id)->value('history_revision'));
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($process->isRunning()) {
                $process->stop();
            }
            if (is_file($marker)) {
                unlink($marker);
            }
        }
    }

    public function test_inbound_after_claim_preserves_snapshot_and_advances_history(): void
    {
        [$org, $brand, $conversation, $channel] = $this->fixture();
        $context = new TenantContext($org->id, $brand->id, source: 'job');
        $first = app(InboundRecorder::class)->ingest($context, $channel->id, 'first-synthetic', [], $conversation->id, 'First synthetic input');
        $marker = base_path('.local/tmp/c02-claim-'.Str::random(16));
        $process = $this->worker('inbound', $org, $brand, $conversation, $channel, $marker);
        DB::beginTransaction();
        try {
            $claim = app(ConversationRepository::class)->claimTurn($context, new TurnCommand($conversation->id, $first->id, $first->text, $first->external_event_id, 'synthetic-claim', 0));
            $process->start();
            $this->waitForMarker($marker);
            $pid = (int) file_get_contents($marker);
            $deadline = microtime(true) + 10;
            do {
                DB::select('select pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->where('pid', $pid)->where('wait_event_type', 'Lock')->exists();
                if (! $waiting) {
                    usleep(10000);
                }
            } while (! $waiting && $process->isRunning() && microtime(true) < $deadline);
            self::assertTrue($waiting, 'Inbound must wait for the claim transaction.');
            DB::commit();
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            self::assertSame('inserted', trim($process->getOutput()));
            $turn = DB::table('seller_turns')->where('id', $claim->turnId)->first();
            self::assertSame(1, $turn->base_history_revision);
            self::assertSame(1, $turn->cutoff_sequence);
            self::assertSame(2, DB::table('conversations')->where('id', $conversation->id)->value('history_revision'));
            self::assertSame(2, DB::table('messages')->where('conversation_id', $conversation->id)->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($process->isRunning()) {
                $process->stop();
            }
            if (is_file($marker)) {
                unlink($marker);
            }
        }
    }

    private function fixture(): array
    {
        $owner = User::create(['name' => 'Synthetic owner', 'email' => Str::random(20).'@example.test', 'password' => 'test-only']);
        $org = Organization::create(['name' => 'Synthetic organization', 'owner_user_id' => $owner->id]);

        return app(TenantContextRunner::class)->run(new TenantContext($org->id, source: 'job'), function () use ($org, $owner): array {
            Membership::create(['user_id' => $owner->id, 'role' => 'owner']);
            $brand = Brand::create(['name' => 'Synthetic brand', 'theme' => 'pyro']);

            return app(TenantContextRunner::class)->run(new TenantContext($org->id, $brand->id, source: 'job'), function () use ($org, $brand): array {
                $channel = ChannelAccount::create(['type' => 'api']);
                $conversation = Conversation::create(['state' => [], 'channel_account_id' => $channel->id]);

                return [$org, $brand, $conversation, $channel];
            });
        });
    }

    private function worker(string $operation, $org, $brand, $conversation, $channel, string $marker): Process
    {
        $process = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), base_path('tests/Feature/Seller/Conversations/Fixtures/history-revision-worker.php'), $operation, $org->id, $brand->id, $conversation->id, $channel->id, $marker, '--env=testing'], base_path());
        $process->setTimeout(15);

        return $process;
    }

    private function waitForMarker(string $marker): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($marker) && microtime(true) < $deadline) {
            usleep(10000);
        }
        self::assertFileExists($marker);
    }
}
