<?php

namespace Tests\Feature\Shell\Onboarding;

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

final class ConcurrentOnboardingTest extends TestCase
{
    public function test_same_payload_is_one_create_and_one_idempotent_replay(): void
    {
        $this->race(['Brand A', 'Brand A'], ['created', 'replay']);
    }

    public function test_different_payloads_create_once_and_report_conflict(): void
    {
        $this->race(['Brand A', 'Brand B'], ['conflict', 'created']);
    }

    private function race(array $names, array $expected): void
    {
        $database = DB::connection()->getDatabaseName();
        self::assertTrue(app()->environment('testing') && $database === config('runtime.test_database') && $database !== config('runtime.preview_database') && str_contains($database, '_test_'));
        Artisan::call('migrate', ['--force' => true]);
        $user = User::create(['name' => 'E102 race fixture', 'email' => 'e102-'.Str::lower(Str::random(20)).'@example.test', 'password' => 'test-only-password']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $org = Organization::create(['name' => 'E102 race fixture']);
        $member = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $user->id, source: 'job'), fn () => Membership::create(['user_id' => $user->id, 'role' => 'owner']));
        $org->forceFill(['owner_user_id' => $user->id])->save();
        $workers = [];
        try {
            DB::beginTransaction();
            DB::table('organizations')->where('id', $org->id)->lockForUpdate()->first();
            foreach ($names as $name) {
                $worker = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/finish-worker.php', $org->id, $user->id, $member->id, $name], base_path(), ['APP_ENV' => 'testing'], timeout: 30);
                $worker->start();
                $workers[] = $worker;
            }
            $pids = [];
            $deadline = microtime(true) + 20;
            while (microtime(true) < $deadline && count($pids) < 2) {
                $pids = [];
                foreach ($workers as $worker) {
                    if (preg_match('/ready:(\\d+)/', $worker->getOutput(), $match)) {
                        $pids[] = (int) $match[1];
                    }
                }
                if (count($pids) < 2) {
                    usleep(10000);
                }
            }
            self::assertCount(2, $pids, 'Both workers must establish separate database connections.');
            $blocked = 0;
            while (microtime(true) < $deadline && $blocked < 2) {
                $blocked = DB::table('pg_locks')->whereIn('pid', $pids)->where('granted', false)->distinct()->count('pid');
                if ($blocked < 2) {
                    usleep(10000);
                }
            }
            self::assertSame(2, $blocked, 'Both workers must be blocked on the held organization lock.');
            DB::commit();
            $statuses = [];
            foreach ($workers as $worker) {
                $worker->wait();
                self::assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $statuses[] = trim(preg_replace('/ready:\\d+\\s*/', '', $worker->getOutput()));
            }
            sort($statuses);
            self::assertSame($expected, $statuses);
            self::assertSame(1, DB::table('brands')->where('organization_id', $org->id)->count());
            self::assertSame(1, DB::table('onboarding_progress')->where('organization_id', $org->id)->whereNotNull('completed_at')->count());
            self::assertSame(1, DB::table('audit_logs')->where('organization_id', $org->id)->where('action', 'onboarding.completed')->count());
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
            DB::table('onboarding_progress')->where('organization_id', $org->id)->delete();
            DB::table('brands')->where('organization_id', $org->id)->delete();
            DB::table('memberships')->where('organization_id', $org->id)->delete();
            DB::table('organizations')->where('id', $org->id)->delete();
            DB::table('users')->where('id', $user->id)->delete();
        }
    }
}
