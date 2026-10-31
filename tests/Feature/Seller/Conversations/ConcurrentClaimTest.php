<?php

namespace Tests\Feature\Seller\Conversations;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Seller\Channels\Core\ChannelAccount;
use App\Seller\Channels\Core\ChannelInboundEvent;
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
}
