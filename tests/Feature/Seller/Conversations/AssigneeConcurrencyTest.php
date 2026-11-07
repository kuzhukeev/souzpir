<?php

namespace Tests\Feature\Seller\Conversations;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\SellerTask;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AssigneeConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_inflight_downgrade_is_seen_after_org_lock_for_both_models_and_save_modes(): void
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'race-owner@example.test', 'password' => 'test-only-password']);
        $organization = Organization::create(['name' => 'Race fixture', 'owner_user_id' => $owner->id]);
        [$brand] = app(TenantContextRunner::class)->run(new TenantContext($organization->id, source: 'job'), function () use ($owner): array {
            Membership::create(['user_id' => $owner->id, 'role' => 'owner']);

            return [Brand::create(['name' => 'Test', 'theme' => 'neutral'])];
        });
        $context = new TenantContext($organization->id, $brand->id, source: 'job');
        foreach ([['conversation', 'normal'], ['conversation', 'quiet'], ['task', 'normal'], ['task', 'quiet']] as [$kind, $mode]) {
            $user = User::create(['name' => 'Assignee', 'email' => Str::lower(Str::random(12)).'@example.test', 'password' => 'test-only-password']);
            $membership = app(TenantContextRunner::class)->run(new TenantContext($organization->id, source: 'job'), fn () => Membership::create(['user_id' => $user->id, 'role' => 'member', 'brand_ids' => [$brand->id]]));
            [$conversation, $task] = app(TenantContextRunner::class)->run($context, function (): array {
                $conversation = Conversation::create(['stage' => 'closed']);

                return [$conversation, SellerTask::create(['conversation_id' => $conversation->id, 'type' => 'followup'])];
            });
            $id = $kind === 'task' ? $task->id : $conversation->id;
            $process = new Process([PHP_BINARY, '-d', 'sys_temp_dir=.local/tmp', base_path('tests/Feature/Seller/Conversations/Fixtures/assignee-race.php'), $organization->id, $brand->id, $user->id, $id, $kind, $mode], base_path(), ['APP_ENV' => 'testing']);
            DB::beginTransaction();
            try {
                DB::table('organizations')->where('id', $organization->id)->lockForUpdate()->first();
                DB::table('memberships')->where('id', $membership->id)->update(['role' => 'viewer']);
                $process->start();
                $deadline = microtime(true) + 10;
                $waiting = false;
                do {
                    DB::select('SELECT pg_stat_clear_snapshot()');
                    $waiting = DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE usename = current_user AND pid <> pg_backend_pid() AND wait_event_type = 'Lock' AND query LIKE '%organizations%') AS waiting")->waiting;
                    if ($waiting) {
                        break;
                    }
                    usleep(20000);
                } while (microtime(true) < $deadline && $process->isRunning());
                self::assertTrue($waiting, 'Worker did not wait for org lock.');
                DB::commit();
                $process->wait();
                self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
                self::assertSame('REJECTED', trim($process->getOutput()));
                self::assertNull(DB::table($kind === 'task' ? 'seller_tasks' : 'conversations')->where('id', $id)->value('assigned_user_id'));
            } finally {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }
}
