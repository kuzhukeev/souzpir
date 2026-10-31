<?php

namespace Tests\Feature\Seller\Conversations;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Foundation\Testing\DatabaseMigrations;
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
}
