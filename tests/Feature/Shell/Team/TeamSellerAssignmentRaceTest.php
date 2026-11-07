<?php

namespace Tests\Feature\Shell\Team;

use App\Seller\Conversations\AssigneeEligibility;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\SellerTask;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/** Checks the Team endpoints against actual Seller instance saves in a second process. */
final class TeamSellerAssignmentRaceTest extends TeamTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(AssigneeEligibility::class) && getenv('TEAM_SELLER_ASSIGNMENT_RACE') !== '1') {
            self::markTestSkipped('Seller assignment locking/eligibility contract is not integrated; TEAM_SELLER_ASSIGNMENT_RACE=1 runs the known failing regression.');
        }
    }

    public static function assignmentCases(): array
    {
        return [
            'conversation role' => ['conversation', 'role'],
            'conversation brand' => ['conversation', 'brand'],
            'conversation disable' => ['conversation', 'disable'],
            'task role' => ['task', 'role'],
            'task brand' => ['task', 'brand'],
            'task disable' => ['task', 'disable'],
        ];
    }

    #[DataProvider('assignmentCases')]
    public function test_assignment_first_blocks_role_brand_and_disable_changes(string $kind, string $change): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$user, $membership] = $this->member($org, 'member', [$brand->id, $other->id]);
        $record = $this->unassigned($org->id, $brand->id, $kind);
        $this->signIn($owner, $brand);
        [$process, $pipes] = $this->worker($org->id, $brand->id, $user->id, $record, $kind, 'assignment-first');
        try {
            fwrite($pipes[0], "go\n");
            fflush($pipes[0]);
            self::assertSame("assigned\n", fgets($pipes[1]), "$kind/$change did not assign");
            $started = microtime(true);
            $response = $this->changeMember($membership->id, $change, $brand->id, $other->id);
            self::assertGreaterThan(0.2, microtime(true) - $started, "$kind/$change did not wait for Seller transaction");
            $response->assertStatus(422);
            self::assertSame($user->id, $this->assignedUser($kind, $record));
            self::assertSame('active', $membership->fresh()->status);
            self::assertSame('member', $membership->fresh()->role);
            self::assertEqualsCanonicalizing([$brand->id, $other->id], $membership->fresh()->brand_ids);
            self::assertSame("committed\n", fgets($pipes[1]));
        } finally {
            $this->stopWorker($process, $pipes);
        }
    }

    #[DataProvider('assignmentCases')]
    public function test_team_change_first_blocks_late_assignment(string $kind, string $change): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$user, $membership] = $this->member($org, 'member', [$brand->id, $other->id]);
        $record = $this->unassigned($org->id, $brand->id, $kind);
        $this->signIn($owner, $brand);
        [$process, $pipes, $pid] = $this->worker($org->id, $brand->id, $user->id, $record, $kind, 'team-first');
        DB::beginTransaction();
        try {
            $this->changeMember($membership->id, $change, $brand->id, $other->id)->assertOk();
            fwrite($pipes[0], "go\n");
            fflush($pipes[0]);
            $deadline = microtime(true) + 8;
            $blocked = false;
            do {
                $blocked = DB::table('pg_locks')->where('pid', $pid)->where('granted', false)->exists();
                if ($blocked || ! proc_get_status($process)['running']) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertTrue($blocked, "$kind/$change did not wait for Team transaction");
            DB::commit();
            self::assertSame("rejected\n", fgets($pipes[1]), "$kind/$change was not rejected");
            self::assertNull($this->assignedUser($kind, $record));
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->stopWorker($process, $pipes);
        }
    }

    private function unassigned(string $organization, string $brand, string $kind): string
    {
        return app(TenantContextRunner::class)->run(new TenantContext($organization, $brand, source: 'job'), function () use ($kind): string {
            $conversation = Conversation::create(['stage' => 'closed']);
            if ($kind === 'conversation') {
                return $conversation->id;
            }

            return SellerTask::create(['conversation_id' => $conversation->id, 'type' => 'followup'])->id;
        });
    }

    private function changeMember(string $id, string $change, string $brand, string $other)
    {
        return $change === 'disable'
            ? $this->postJson('/team/'.$id.'/disable')
            : $this->patchJson('/team/'.$id, ['role' => $change === 'role' ? 'viewer' : 'editor',
                'brand_ids' => $change === 'brand' ? [$other] : [$brand, $other]]);
    }

    private function assignedUser(string $kind, string $id): mixed
    {
        return DB::table($kind === 'conversation' ? 'conversations' : 'seller_tasks')->where('id', $id)->value('assigned_user_id');
    }

    private function worker(string $organization, string $brand, string $user, string $record, string $kind, string $order): array
    {
        $command = [PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), base_path('tests/Feature/Shell/Team/Fixtures/assignment-race.php'), $organization, $brand, $user, $record, $kind, $order];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), array_merge(getenv(), ['APP_ENV' => 'testing']), ['bypass_shell' => true]);
        self::assertIsResource($process);
        stream_set_timeout($pipes[1], 15);
        $ready = fgets($pipes[1]);
        if (! is_string($ready) || ! preg_match('/^ready:(\d+)\s*$/D', $ready, $match)) {
            $this->stopWorker($process, $pipes);
            self::fail('Seller worker failed to start.');
        }

        return [$process, $pipes, (int) $match[1]];
    }

    private function stopWorker($process, array $pipes): void
    {
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        if (proc_get_status($process)['running']) {
            proc_terminate($process);
        }
        proc_close($process);
    }
}
