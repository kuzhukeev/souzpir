<?php

namespace Tests\Feature\Seller\Conversations;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Seller\Channels\Core\ChannelAccount;
use App\Seller\Channels\Core\ChannelInboundEvent;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ConcurrentClaimTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_processes_claim_same_turn_once(): void
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'concurrent@example.test', 'password' => 'test-only-password']);
        $organization = Organization::create(['name' => 'Test', 'owner_user_id' => $owner->id]);
        [$brand, $conversation, $message] = app(TenantContextRunner::class)->run(new TenantContext($organization->id, source: 'job'), function () use ($organization, $owner): array {
            Membership::create(['user_id' => $owner->id, 'role' => 'owner']);
            $brand = Brand::create(['name' => 'Test', 'theme' => 'pyro']);

            return app(TenantContextRunner::class)->run(new TenantContext($organization->id, $brand->id, source: 'job'), function () use ($brand): array {
                $conversation = Conversation::create(['state' => []]);
                $message = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Concurrent', 'meta' => []]);

                return [$brand, $conversation, $message];
            });
        });
        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'claim-'.Str::random(24);
        $script = base_path('tests/Feature/Seller/Conversations/Fixtures/concurrent-claim.php');
        $args = [PHP_BINARY, $script, $barrier, $organization->id, $brand->id, $conversation->id, $message->id];
        $processes = [new Process($args, base_path(), ['APP_ENV' => 'testing']), new Process($args, base_path(), ['APP_ENV' => 'testing'])];
        try {
            foreach ($processes as $process) {
                $process->start();
            }
            file_put_contents($barrier, 'go');
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            }
            $statuses = array_map(fn (Process $process) => trim($process->getOutput()), $processes);
            sort($statuses);
            $this->assertSame(['claimed', 'in_progress'], $statuses);
            $this->assertDatabaseCount('seller_turns', 1);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            if (file_exists($barrier)) {
                unlink($barrier);
            }
        }
    }

    public function test_two_conversations_cannot_concurrently_consume_the_same_event(): void
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'race@example.test', 'password' => 'test-only-password']);
        $organization = Organization::create(['name' => 'Test', 'owner_user_id' => $owner->id]);
        [$brand, $event, $first, $second] = app(TenantContextRunner::class)->run(new TenantContext($organization->id, source: 'job'), function () use ($organization, $owner): array {
            Membership::create(['user_id' => $owner->id, 'role' => 'owner']);
            $brand = Brand::create(['name' => 'Test', 'theme' => 'pyro']);

            return app(TenantContextRunner::class)->run(new TenantContext($organization->id, $brand->id, source: 'job'), function () use ($brand): array {
                $channel = ChannelAccount::create(['type' => 'api']);
                $event = ChannelInboundEvent::create(['channel_account_id' => $channel->id, 'external_id' => 'event', 'payload' => []]);
                $makeConversation = function () use ($channel): array {
                    $conversation = Conversation::create(['channel_account_id' => $channel->id, 'state' => []]);
                    $message = Message::create(['conversation_id' => $conversation->id, 'role' => 'system', 'text' => 'Concurrent', 'meta' => []]);

                    return [$conversation, $message];
                };

                return [$brand, $event, $makeConversation(), $makeConversation()];
            });
        });
        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'event-'.Str::random(24);
        $script = base_path('tests/Feature/Seller/Conversations/Fixtures/concurrent-claim.php');
        $processes = [];
        foreach ([$first, $second] as $i => [$conversation, $message]) {
            $args = [PHP_BINARY, $script, $barrier, $organization->id, $brand->id, $conversation->id, $message->id, $event->id, 'event-key-'.$i, $event->channel_account_id];
            $processes[] = new Process($args, base_path(), ['APP_ENV' => 'testing']);
        }
        try {
            foreach ($processes as $process) {
                $process->start();
            }
            file_put_contents($barrier, 'go');
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            }
            $statuses = array_map(fn (Process $process) => trim($process->getOutput()), $processes);
            sort($statuses);
            $this->assertSame(['claimed', 'conflict'], $statuses);
            $this->assertDatabaseCount('seller_turns', 1);
            $this->assertDatabaseHas('seller_turns', ['external_event_id' => $event->id]);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            if (file_exists($barrier)) {
                unlink($barrier);
            }
        }
    }

    public function test_waiting_claim_rechecks_brand_status_after_lock(): void
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'brand-race@example.test', 'password' => 'test-only-password']);
        $organization = Organization::create(['name' => 'Test', 'owner_user_id' => $owner->id]);
        [$brand, $conversation, $message] = app(TenantContextRunner::class)->run(new TenantContext($organization->id, source: 'job'), function () use ($organization, $owner): array {
            Membership::create(['user_id' => $owner->id, 'role' => 'owner']);
            $brand = Brand::create(['name' => 'Test', 'theme' => 'pyro']);

            return app(TenantContextRunner::class)->run(new TenantContext($organization->id, $brand->id, source: 'job'), function () use ($brand): array {
                $conversation = Conversation::create(['state' => []]);
                $message = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Concurrent', 'meta' => []]);

                return [$brand, $conversation, $message];
            });
        });
        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'brand-'.Str::random(24);
        $process = new Process([PHP_BINARY, base_path('tests/Feature/Seller/Conversations/Fixtures/concurrent-claim.php'), $barrier, $organization->id, $brand->id, $conversation->id, $message->id], base_path(), ['APP_ENV' => 'testing']);
        DB::beginTransaction();
        try {
            DB::table('brands')->where('id', $brand->id)->update(['status' => 'archived']);
            $process->start();
            file_put_contents($barrier, 'go');
            $deadline = microtime(true) + 8;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE usename = current_user AND pid <> pg_backend_pid() AND wait_event_type = 'Lock' AND query LIKE '%brands%') AS waiting")->waiting;
                if ($waiting) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline && $process->isRunning());
            $this->assertTrue($waiting, 'Worker did not reach brand lock');
            DB::commit();
            $process->wait();
            $this->assertFalse($process->isSuccessful());
            $this->assertStringContainsString('Active brand is required', $process->getErrorOutput());
            $this->assertDatabaseCount('seller_turns', 0);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($process->isRunning()) {
                $process->stop();
            }
            if (file_exists($barrier)) {
                unlink($barrier);
            }
        }
    }

    public function test_channel_update_and_internal_message_creation_are_serialized_in_both_orders(): void
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'channel-race@example.test', 'password' => 'test-only-password']);
        $organization = Organization::create(['name' => 'Test', 'owner_user_id' => $owner->id]);
        [$brand, $channel, $first, $second] = app(TenantContextRunner::class)->run(new TenantContext($organization->id, source: 'job'), function () use ($organization, $owner): array {
            Membership::create(['user_id' => $owner->id, 'role' => 'owner']);
            $brand = Brand::create(['name' => 'Test', 'theme' => 'pyro']);

            return app(TenantContextRunner::class)->run(new TenantContext($organization->id, $brand->id, source: 'job'), fn () => [
                $brand, ChannelAccount::create(['type' => 'api']), Conversation::create(['state' => []]), Conversation::create(['state' => []]),
            ]);
        });
        foreach ([['change', $first], ['insert', $second]] as [$operation, $conversation]) {
            $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'channel-'.Str::random(24);
            $process = new Process([PHP_BINARY, base_path('tests/Feature/Seller/Conversations/Fixtures/channel-race.php'), $barrier, $operation, $organization->id, $brand->id, $conversation->id, $channel->id], base_path(), ['APP_ENV' => 'testing']);
            DB::beginTransaction();
            try {
                if ($operation === 'change') {
                    DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'brand_id' => $brand->id, 'conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'internal']);
                } else {
                    DB::table('conversations')->where('id', $conversation->id)->update(['channel_account_id' => $channel->id]);
                }
                $process->start();
                file_put_contents($barrier, 'go');
                $deadline = microtime(true) + 8;
                do {
                    DB::select('SELECT pg_stat_clear_snapshot()');
                    $waiting = DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE usename = current_user AND pid <> pg_backend_pid() AND wait_event_type = 'Lock' AND (query LIKE '%conversations%' OR query LIKE '%messages%')) AS waiting")->waiting;
                    if ($waiting) {
                        break;
                    }
                    usleep(20000);
                } while (microtime(true) < $deadline && $process->isRunning());
                $this->assertTrue($waiting, 'Worker did not reach the conversation lock');
                DB::commit();
                $process->wait();
                $this->assertFalse($process->isSuccessful(), 'Conflicting operation succeeded');
                $this->assertStringContainsString('23514', $process->getErrorOutput());
                if ($operation === 'change') {
                    $this->assertDatabaseHas('conversations', ['id' => $conversation->id, 'channel_account_id' => null]);
                } else {
                    $this->assertDatabaseHas('conversations', ['id' => $conversation->id, 'channel_account_id' => $channel->id]);
                    $this->assertDatabaseMissing('messages', ['conversation_id' => $conversation->id]);
                }
            } finally {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                if ($process->isRunning()) {
                    $process->stop();
                }
                if (file_exists($barrier)) {
                    unlink($barrier);
                }
            }
        }
    }

    public function test_edit_and_claim_are_serialized_in_both_orders(): void
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'input-race@example.test', 'password' => 'test-only-password']);
        $organization = Organization::create(['name' => 'Test', 'owner_user_id' => $owner->id]);
        [$brand, $first, $second] = app(TenantContextRunner::class)->run(new TenantContext($organization->id, source: 'job'), function () use ($organization, $owner): array {
            Membership::create(['user_id' => $owner->id, 'role' => 'owner']);
            $brand = Brand::create(['name' => 'Test', 'theme' => 'pyro']);

            return app(TenantContextRunner::class)->run(new TenantContext($organization->id, $brand->id, source: 'job'), function () use ($brand): array {
                $make = function (): array {
                    $conversation = Conversation::create(['state' => []]);
                    $message = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Concurrent', 'meta' => []]);

                    return [$conversation, $message];
                };

                return [$brand, $make(), $make()];
            });
        });
        foreach ([['claim', $first], ['edit', $second]] as [$operation, [$conversation, $message]]) {
            $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'input-'.Str::random(24);
            if ($operation === 'claim') {
                $args = [PHP_BINARY, base_path('tests/Feature/Seller/Conversations/Fixtures/concurrent-claim.php'), $barrier, $organization->id, $brand->id, $conversation->id, $message->id];
            } else {
                $args = [PHP_BINARY, base_path('tests/Feature/Seller/Conversations/Fixtures/channel-race.php'), $barrier, 'edit', $organization->id, $brand->id, $conversation->id, $message->id];
            }
            $process = new Process($args, base_path(), ['APP_ENV' => 'testing']);
            DB::beginTransaction();
            try {
                if ($operation === 'claim') {
                    DB::table('messages')->where('id', $message->id)->update(['text' => 'changed']);
                } else {
                    app(ConversationRepository::class)->claimTurn(new TenantContext($organization->id, $brand->id, source: 'job'), new TurnCommand($conversation->id, $message->id, $message->text, null, 'race-claim', 0));
                }
                $process->start();
                file_put_contents($barrier, 'go');
                $deadline = microtime(true) + 8;
                do {
                    DB::select('SELECT pg_stat_clear_snapshot()');
                    $waiting = DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE usename = current_user AND pid <> pg_backend_pid() AND wait_event_type = 'Lock' AND (query LIKE '%conversations%' OR query LIKE '%messages%')) AS waiting")->waiting;
                    if ($waiting) {
                        break;
                    }
                    usleep(20000);
                } while (microtime(true) < $deadline && $process->isRunning());
                $this->assertTrue($waiting, 'Worker did not reach input or conversation lock');
                DB::commit();
                $process->wait();
                if ($operation === 'claim') {
                    $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                    $this->assertSame('conflict', trim($process->getOutput()));
                    $this->assertDatabaseMissing('seller_turns', ['input_message_id' => $message->id]);
                    $this->assertDatabaseHas('messages', ['id' => $message->id, 'text' => 'changed']);
                } else {
                    $this->assertFalse($process->isSuccessful());
                    $this->assertStringContainsString('23514', $process->getErrorOutput());
                    $this->assertDatabaseHas('seller_turns', ['input_message_id' => $message->id, 'status' => 'processing']);
                    $this->assertDatabaseHas('messages', ['id' => $message->id, 'text' => 'Concurrent']);
                }
            } finally {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                if ($process->isRunning()) {
                    $process->stop();
                }
                if (file_exists($barrier)) {
                    unlink($barrier);
                }
            }
        }
    }
}
