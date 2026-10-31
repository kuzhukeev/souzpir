<?php

namespace Tests\Feature\Shell\Organizations;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ConcurrentOwnerTransferTest extends TestCase
{
    public function test_competing_transfers_have_one_owner_and_one_audit_event(): void
    {
        $db = DB::connection()->getDatabaseName();
        self::assertTrue(app()->environment('testing') && $db === config('runtime.test_database') && $db !== config('runtime.preview_database') && str_contains($db, '_test_'));
        Artisan::call('migrate', ['--force' => true]);
        $users = [];
        foreach (range(1, 3) as $n) {
            $user = User::create(['name' => 'E104 transfer fixture '.$n, 'email' => 'e104-'.Str::lower(Str::random(20)).'@example.test', 'password' => 'test-only-password']);
            $user->forceFill(['email_verified_at' => now()])->save();
            $users[] = $user;
        }
        $org = Organization::create(['name' => 'E104 transfer fixture']);
        [$member, $brand] = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $users[0]->id, source: 'job'), function () use ($users): array {
            $owner = Membership::create(['user_id' => $users[0]->id, 'role' => 'owner']);
            Membership::create(['user_id' => $users[1]->id, 'role' => 'admin']);
            Membership::create(['user_id' => $users[2]->id, 'role' => 'admin']);

            return [$owner, Brand::create(['name' => 'Existing'])];
        });
        $org->forceFill(['owner_user_id' => $users[0]->id])->save();
        $workers = [];
        try {
            DB::beginTransaction();
            DB::table('organizations')->where('id', $org->id)->lockForUpdate()->first();
            foreach ([$users[1], $users[2]] as $target) {
                $worker = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/transfer-worker.php', $org->id, $brand->id, $users[0]->id, $member->id, $target->id], base_path(), ['APP_ENV' => 'testing'], timeout: 30);
                $worker->start();
                $workers[] = $worker;
            }
            $pids = [];
            $deadline = microtime(true) + 20;
            while (microtime(true) < $deadline && count($pids) < 2) {
                $pids = [];
                foreach ($workers as $worker) {
                    if (preg_match('/ready:(\d+)/', $worker->getOutput(), $match)) {
                        $pids[] = (int) $match[1];
                    }
                }
                if (count($pids) < 2) {
                    usleep(10000);
                }
            }
            self::assertCount(2, $pids);
            $blocked = 0;
            while (microtime(true) < $deadline && $blocked < 2) {
                $blocked = DB::table('pg_locks')->whereIn('pid', $pids)->where('granted', false)->distinct()->count('pid');
                if ($blocked < 2) {
                    usleep(10000);
                }
            }
            self::assertSame(2, $blocked);
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                self::assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $results[] = trim(preg_replace('/ready:\d+\s*/', '', $worker->getOutput()));
            }
            sort($results);
            self::assertSame(['denied', 'transferred'], $results);
            self::assertSame(1, DB::table('memberships')->where('organization_id', $org->id)->where('role', 'owner')->where('status', 'active')->count());
            self::assertContains($org->fresh()->owner_user_id, [$users[1]->id, $users[2]->id]);
            self::assertSame(1, DB::table('audit_logs')->where('organization_id', $org->id)->where('action', 'organization.owner.transferred')->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            DB::table('audit_logs')->where('organization_id', $org->id)->delete();
            DB::table('brand_modules')->where('organization_id', $org->id)->delete();
            DB::table('brands')->where('organization_id', $org->id)->delete();
            DB::table('memberships')->where('organization_id', $org->id)->delete();
            DB::table('organizations')->where('id', $org->id)->delete();
            DB::table('auth_session_registry')->whereIn('user_id', array_map(fn ($user) => $user->id, $users))->delete();
            DB::table('users')->whereIn('id', array_map(fn ($user) => $user->id, $users))->delete();
        }
    }
}
