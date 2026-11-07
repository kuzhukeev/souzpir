<?php

namespace Tests\Feature\Seller\Conversations;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Seller\Conversations\Models\SellerTask;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DatabaseAssignmentConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_direct_sql_conflicts_fail_fast_for_both_orders_and_all_membership_changes(): void
    {
        [$org, $brand, $other] = $this->cabinet();
        foreach (['conversation', 'task'] as $kind) {
            foreach (['role', 'brand_ids', 'status'] as $change) {
                foreach (['assignment-first', 'membership-first'] as $order) {
                    [$user, $membership, $record] = $this->fixture($org, $brand, $other, $kind);
                    $value = match ($change) {
                        'role' => 'viewer',
                        'brand_ids' => json_encode([$other->id], JSON_THROW_ON_ERROR),
                        'status' => 'disabled',
                    };
                    $table = $kind === 'task' ? 'seller_tasks' : 'conversations';
                    $worker = $order === 'assignment-first'
                        ? ['membership', $membership->id, $change, $value]
                        : [$kind, $record->id, 'assigned_user_id', $user->id];
                    [$process, $pipes] = $this->worker($worker);
                    DB::beginTransaction();
                    try {
                        if ($order === 'assignment-first') {
                            DB::table($table)->where('id', $record->id)->update(['assigned_user_id' => $user->id]);
                        } else {
                            DB::table('memberships')->where('id', $membership->id)->update([$change => $value]);
                        }
                        $start = microtime(true);
                        fwrite($pipes[0], "go\n");
                        fflush($pipes[0]);
                        self::assertSame("55P03\n", fgets($pipes[1]), "$kind/$change/$order did not fail closed");
                        self::assertLessThan(3.0, microtime(true) - $start, "$kind/$change/$order waited for an advisory lock");
                        DB::commit();
                        $assigned = DB::table($table)->where('id', $record->id)->value('assigned_user_id');
                        self::assertSame($order === 'assignment-first' ? $user->id : null, $assigned);
                        $fresh = DB::table('memberships')->where('id', $membership->id)->first();
                        self::assertSame($order === 'membership-first' && $change === 'role' ? 'viewer' : 'member', $fresh->role);
                        self::assertSame($order === 'membership-first' && $change === 'status' ? 'disabled' : 'active', $fresh->status);
                        self::assertEqualsCanonicalizing($order === 'membership-first' && $change === 'brand_ids' ? [$other->id] : [$brand->id, $other->id], json_decode($fresh->brand_ids, true));
                    } finally {
                        if (DB::transactionLevel() > 0) {
                            DB::rollBack();
                        }
                        foreach ($pipes as $pipe) {
                            fclose($pipe);
                        }
                        if (proc_get_status($process)['running']) {
                            proc_terminate($process);
                        }
                        proc_close($process);
                    }
                }
            }
        }
    }

    public function test_non_read_committed_transactions_are_rejected_and_rolled_back(): void
    {
        [$org, $brand, $other] = $this->cabinet();
        [$user, $membership, $record] = $this->fixture($org, $brand, $other, 'conversation');
        foreach (['REPEATABLE READ', 'SERIALIZABLE'] as $isolation) {
            foreach (['assignment', 'membership', 'brand', 'organization'] as $operation) {
                DB::beginTransaction();
                try {
                    DB::statement('SET TRANSACTION ISOLATION LEVEL '.$isolation);
                    try {
                        if ($operation === 'assignment') {
                            DB::table('conversations')->where('id', $record->id)->update(['assigned_user_id' => $user->id]);
                        } elseif ($operation === 'membership') {
                            DB::table('memberships')->where('id', $membership->id)->update(['role' => 'viewer']);
                        } elseif ($operation === 'brand') {
                            DB::table('brands')->where('id', $brand->id)->update(['status' => 'archived']);
                        } else {
                            DB::table('organizations')->where('id', $org->id)->update(['status' => 'disabled']);
                        }
                        self::fail("$isolation $operation accepted");
                    } catch (QueryException $exception) {
                        self::assertSame('25001', $exception->errorInfo[0] ?? null);
                    }
                } finally {
                    DB::rollBack();
                }
                self::assertNull(DB::table('conversations')->where('id', $record->id)->value('assigned_user_id'));
                self::assertSame('member', DB::table('memberships')->where('id', $membership->id)->value('role'));
            }
        }
    }

    public function test_savepoint_rollback_discards_assignment_and_its_lock(): void
    {
        [$org, $brand, $other] = $this->cabinet();
        [$user, $membership, $record] = $this->fixture($org, $brand, $other, 'conversation');
        [$process, $pipes] = $this->worker(['membership', $membership->id, 'role', 'viewer']);
        DB::beginTransaction();
        try {
            DB::beginTransaction();
            DB::table('conversations')->where('id', $record->id)->update(['assigned_user_id' => $user->id]);
            DB::rollBack();
            fwrite($pipes[0], "go\n");
            fflush($pipes[0]);
            self::assertSame("accepted\n", fgets($pipes[1]));
            DB::commit();
            self::assertNull(DB::table('conversations')->where('id', $record->id)->value('assigned_user_id'));
            self::assertSame('viewer', DB::table('memberships')->where('id', $membership->id)->value('role'));
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            proc_close($process);
        }
    }

    public function test_commit_turn_and_membership_changes_serialize_in_both_orders(): void
    {
        foreach (['role', 'brand_ids', 'status'] as $change) {
            foreach (['commit-first', 'membership-first'] as $order) {
                [$org, $brand, $other] = $this->cabinet();
                [$user, $membership, $conversation] = $this->fixture($org, $brand, $other, 'conversation');
                $context = new TenantContext($org->id, $brand->id, source: 'job');
                $message = app(TenantContextRunner::class)->run($context, fn () => Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Fixture', 'meta' => []]));
                $claim = app(ConversationRepository::class)->claimTurn($context, new TurnCommand($conversation->id, $message->id, $message->text, null, (string) Str::ulid(), 0));
                $taskId = (string) Str::ulid();
                $payload = ['turn' => $claim->turnId, 'conversation' => $conversation->id, 'org' => $org->id, 'brand' => $brand->id, 'token' => $claim->token(), 'task' => $taskId, 'user' => $user->id, 'order' => $order];
                $value = match ($change) {
                    'role' => 'viewer',
                    'brand_ids' => json_encode([$other->id], JSON_THROW_ON_ERROR),
                    'status' => 'disabled',
                };
                [$process, $pipes, $pid] = $this->commitWorker();
                try {
                    if ($order === 'membership-first') {
                        DB::beginTransaction();
                        DB::table('organizations')->where('id', $org->id)->lockForUpdate()->first();
                        DB::table('memberships')->where('id', $membership->id)->update([$change => $value]);
                    }
                    fwrite($pipes[0], json_encode($payload, JSON_THROW_ON_ERROR)."\n");
                    fflush($pipes[0]);
                    if ($order === 'commit-first') {
                        self::assertSame("assigned\n", fgets($pipes[1]));
                        try {
                            DB::table('memberships')->where('id', $membership->id)->update([$change => $value]);
                            self::fail('Membership mutation overtook a pending commitTurn.');
                        } catch (QueryException $exception) {
                            self::assertSame('55P03', $exception->errorInfo[0] ?? null);
                        }
                        fwrite($pipes[0], "commit\n");
                        fflush($pipes[0]);
                        self::assertSame("committed\n", fgets($pipes[1]));
                        self::assertSame($user->id, DB::table('seller_tasks')->where('id', $taskId)->value('assigned_user_id'));
                        self::assertSame('completed', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
                    } else {
                        $deadline = microtime(true) + 8;
                        $waiting = false;
                        do {
                            DB::select('SELECT pg_stat_clear_snapshot()');
                            $waiting = (bool) (DB::selectOne("SELECT wait_event_type = 'Lock' AS waiting FROM pg_stat_activity WHERE pid = ?", [$pid])->waiting ?? false);
                            if ($waiting || ! proc_get_status($process)['running']) {
                                break;
                            }
                            usleep(10000);
                        } while (microtime(true) < $deadline);
                        self::assertTrue($waiting, 'commitTurn did not wait for the Team organization lock.');
                        DB::commit();
                        self::assertSame("rejected:422\n", fgets($pipes[1]));
                        self::assertFalse(DB::table('seller_tasks')->where('id', $taskId)->exists());
                        self::assertSame('processing', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
                        self::assertSame(0, DB::table('conversations')->where('id', $conversation->id)->value('version'));
                    }
                } finally {
                    if (DB::transactionLevel() > 0) {
                        DB::rollBack();
                    }
                    foreach ($pipes as $pipe) {
                        fclose($pipe);
                    }
                    if (proc_get_status($process)['running']) {
                        proc_terminate($process);
                    }
                    proc_close($process);
                }
            }
        }
    }

    public function test_direct_archive_and_disable_cannot_race_a_new_assignment(): void
    {
        foreach (['conversation', 'task'] as $kind) {
            foreach (['brand', 'organization'] as $target) {
                foreach (['assignment-first', 'availability-first'] as $order) {
                    [$org, $brand, $other] = $this->cabinet();
                    [$user, , $record] = $this->fixture($org, $brand, $other, $kind);
                    $table = $kind === 'task' ? 'seller_tasks' : 'conversations';
                    $targetId = $target === 'brand' ? $brand->id : $org->id;
                    $targetTable = $target === 'brand' ? 'brands' : 'organizations';
                    $targetStatus = $target === 'brand' ? 'archived' : 'disabled';
                    $arguments = $order === 'assignment-first'
                        ? [$target, $targetId, 'status', $targetStatus]
                        : [$kind, $record->id, 'assigned_user_id', $user->id];
                    [$process, $pipes] = $this->worker($arguments);
                    DB::beginTransaction();
                    try {
                        if ($order === 'assignment-first') {
                            DB::table($table)->where('id', $record->id)->update(['assigned_user_id' => $user->id]);
                        } else {
                            DB::table($targetTable)->where('id', $targetId)->update(['status' => $targetStatus]);
                        }
                        $started = microtime(true);
                        fwrite($pipes[0], "go\n");
                        fflush($pipes[0]);
                        self::assertSame("55P03\n", fgets($pipes[1]), "$kind/$target/$order did not fail closed");
                        self::assertLessThan(3.0, microtime(true) - $started);
                        DB::commit();
                        self::assertSame($order === 'assignment-first' ? $user->id : null, DB::table($table)->where('id', $record->id)->value('assigned_user_id'));
                        self::assertSame($order === 'availability-first' ? $targetStatus : 'active', DB::table($targetTable)->where('id', $targetId)->value('status'));
                    } finally {
                        if (DB::transactionLevel() > 0) {
                            DB::rollBack();
                        }
                        foreach ($pipes as $pipe) {
                            fclose($pipe);
                        }
                        if (proc_get_status($process)['running']) {
                            proc_terminate($process);
                        }
                        proc_close($process);
                    }
                }
            }
        }
    }

    public function test_independent_organizations_do_not_block_one_another(): void
    {
        [$firstOrg, $firstBrand, $firstOther] = $this->cabinet();
        [$secondOrg, $secondBrand, $secondOther] = $this->cabinet();
        [$firstUser, , $first] = $this->fixture($firstOrg, $firstBrand, $firstOther, 'conversation');
        [$secondUser, , $second] = $this->fixture($secondOrg, $secondBrand, $secondOther, 'conversation');
        [$process, $pipes] = $this->worker(['conversation', $second->id, 'assigned_user_id', $secondUser->id]);
        DB::beginTransaction();
        try {
            DB::table('conversations')->where('id', $first->id)->update(['assigned_user_id' => $firstUser->id]);
            $started = microtime(true);
            fwrite($pipes[0], "go\n");
            fflush($pipes[0]);
            self::assertSame("accepted\n", fgets($pipes[1]));
            self::assertLessThan(3.0, microtime(true) - $started);
            DB::rollBack();
            self::assertNull(DB::table('conversations')->where('id', $first->id)->value('assigned_user_id'));
            self::assertSame($secondUser->id, DB::table('conversations')->where('id', $second->id)->value('assigned_user_id'));
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            proc_close($process);
        }
    }

    public function test_guard_observes_commits_after_a_waiting_statement_took_its_snapshot(): void
    {
        [$org, $brand, $other] = $this->cabinet();
        config(['database.connections.guard_other' => config('database.connections.pgsql')]);
        $otherConnection = DB::connection('guard_other');
        foreach (['conversation', 'task'] as $kind) {
            foreach (['assignment', 'membership'] as $operation) {
                [$user, $membership, $record] = $this->fixture($org, $brand, $other, $kind);
                $table = $kind === 'task' ? 'seller_tasks' : 'conversations';
                $arguments = $operation === 'assignment'
                    ? [$kind, $record->id, 'assigned_user_id', $user->id]
                    : ['membership', $membership->id, 'role', 'viewer'];
                [$process, $pipes, $pid] = $this->worker($arguments);
                DB::beginTransaction();
                try {
                    $locked = $operation === 'assignment' ? $table : 'memberships';
                    $lockedId = $operation === 'assignment' ? $record->id : $membership->id;
                    // A foreign-key check on the concurrent assignment takes KEY SHARE.
                    // NO KEY UPDATE still blocks the worker but permits that check.
                    DB::select('SELECT id FROM '.$locked.' WHERE id = ? FOR NO KEY UPDATE', [$lockedId]);
                    fwrite($pipes[0], "go\n");
                    fflush($pipes[0]);
                    $deadline = microtime(true) + 8;
                    $waiting = false;
                    do {
                        DB::select('SELECT pg_stat_clear_snapshot()');
                        $waiting = (bool) (DB::selectOne("SELECT wait_event_type = 'Lock' AS waiting FROM pg_stat_activity WHERE pid = ?", [$pid])->waiting ?? false);
                        if ($waiting || ! proc_get_status($process)['running']) {
                            break;
                        }
                        usleep(10000);
                    } while (microtime(true) < $deadline);
                    self::assertTrue($waiting, "$kind/$operation did not reach the row lock");
                    if ($operation === 'assignment') {
                        $otherConnection->table('memberships')->where('id', $membership->id)->update(['role' => 'viewer']);
                    } else {
                        $otherConnection->table($table)->where('id', $record->id)->update(['assigned_user_id' => $user->id]);
                    }
                    DB::commit();
                    self::assertSame("23514\n", fgets($pipes[1]), "$kind/$operation observed a stale snapshot");
                    self::assertSame($operation === 'assignment' ? null : $user->id, DB::table($table)->where('id', $record->id)->value('assigned_user_id'));
                    self::assertSame($operation === 'assignment' ? 'viewer' : 'member', DB::table('memberships')->where('id', $membership->id)->value('role'));
                } finally {
                    if (DB::transactionLevel() > 0) {
                        DB::rollBack();
                    }
                    foreach ($pipes as $pipe) {
                        fclose($pipe);
                    }
                    if (proc_get_status($process)['running']) {
                        proc_terminate($process);
                    }
                    proc_close($process);
                }
            }
        }
        DB::disconnect('guard_other');
    }

    public function test_migration_refuses_legacy_invalid_assignments_atomically(): void
    {
        [$org, $brand, $other] = $this->cabinet();
        [$user, $membership, $record] = $this->fixture($org, $brand, $other, 'conversation');
        DB::table('memberships')->where('id', $membership->id)->update(['role' => 'viewer']);
        $migration = require database_path('migrations/E3-04/2026_11_01_000000_e3_04_guard_seller_assignments.php');
        DB::transaction(fn () => $migration->down());
        DB::table('conversations')->where('id', $record->id)->update(['assigned_user_id' => $user->id]);
        try {
            DB::transaction(fn () => $migration->up());
            self::fail('Invalid legacy assignments accepted by migration.');
        } catch (QueryException $exception) {
            self::assertSame('23514', $exception->errorInfo[0] ?? null);
        }
        self::assertNull(DB::scalar("SELECT to_regprocedure('public.seller_assignment_roles()')"));
        self::assertSame($user->id, DB::table('conversations')->where('id', $record->id)->value('assigned_user_id'));
        DB::table('conversations')->where('id', $record->id)->update(['assigned_user_id' => null]);
        DB::transaction(fn () => $migration->up());
        self::assertNotNull(DB::scalar("SELECT to_regprocedure('public.seller_assignment_roles()')"));
    }

    public function test_migration_refuses_different_permission_config(): void
    {
        $migration = require database_path('migrations/E3-04/2026_11_01_000000_e3_04_guard_seller_assignments.php');
        DB::transaction(fn () => $migration->down());
        $abilities = config('permissions.abilities');
        $abilities['seller.dialogs.write'] = ['owner', 'admin', 'editor'];
        config(['permissions.abilities' => $abilities]);
        try {
            DB::transaction(fn () => $migration->up());
            self::fail('Migration accepted an unsupported permission policy.');
        } catch (\LogicException) {
            self::assertNull(DB::scalar("SELECT to_regprocedure('public.seller_assignment_roles()')"));
        }
    }

    private function cabinet(): array
    {
        $owner = User::create(['name' => 'Owner', 'email' => Str::lower(Str::random(12)).'@example.test', 'password' => 'test-only-password']);
        $org = Organization::create(['name' => 'Race fixture', 'owner_user_id' => $owner->id]);
        [$brand, $other] = app(TenantContextRunner::class)->run(new TenantContext($org->id, source: 'job'), function () use ($owner): array {
            Membership::create(['user_id' => $owner->id, 'role' => 'owner']);

            return [Brand::create(['name' => 'A', 'theme' => 'neutral']), Brand::create(['name' => 'B', 'theme' => 'neutral'])];
        });

        return [$org, $brand, $other];
    }

    private function fixture(Organization $org, Brand $brand, Brand $other, string $kind): array
    {
        $user = User::create(['name' => 'Member', 'email' => Str::lower(Str::random(12)).'@example.test', 'password' => 'test-only-password']);
        $membership = app(TenantContextRunner::class)->run(new TenantContext($org->id, source: 'job'), fn () => Membership::create(['user_id' => $user->id, 'role' => 'member', 'brand_ids' => [$brand->id, $other->id]]));
        $record = app(TenantContextRunner::class)->run(new TenantContext($org->id, $brand->id, source: 'job'), function () use ($kind) {
            $conversation = Conversation::create(['stage' => 'closed']);

            return $kind === 'task' ? SellerTask::create(['conversation_id' => $conversation->id, 'type' => 'followup']) : $conversation;
        });

        return [$user, $membership, $record];
    }

    private function worker(array $arguments): array
    {
        $command = [PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), base_path('tests/Feature/Seller/Conversations/Fixtures/database-assignment-worker.php'), ...$arguments];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), array_merge(getenv(), ['APP_ENV' => 'testing']), ['bypass_shell' => true]);
        self::assertIsResource($process);
        stream_set_timeout($pipes[1], 8);
        $ready = fgets($pipes[1]);
        self::assertMatchesRegularExpression('/^ready:(\d+)\s*$/D', $ready);
        preg_match('/^ready:(\d+)\s*$/D', $ready, $match);

        return [$process, $pipes, (int) $match[1]];
    }

    private function commitWorker(): array
    {
        $command = [PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), base_path('tests/Feature/Seller/Conversations/Fixtures/commit-assignment-worker.php')];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), array_merge(getenv(), ['APP_ENV' => 'testing']), ['bypass_shell' => true]);
        self::assertIsResource($process);
        stream_set_timeout($pipes[1], 8);
        $ready = fgets($pipes[1]);
        self::assertMatchesRegularExpression('/^ready:(\d+)\s*$/D', $ready);
        preg_match('/^ready:(\d+)\s*$/D', $ready, $match);

        return [$process, $pipes, (int) $match[1]];
    }
}
