<?php

namespace Tests\Feature\Shell\Auth;

use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\MfaCode;
use App\Tenancy\Cabinet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Support\IsolatedDatabaseMigrations;
use Tests\Support\MfaTestDatabase;
use Tests\TestCase;

#[Group('race')]
final class MfaRecoveryConcurrencyTest extends TestCase
{
    use IsolatedDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        MfaTestDatabase::assert();
    }

    public function test_two_processes_cannot_consume_the_same_recovery_code(): void
    {
        $user = User::create(['name' => 'Synthetic', 'email' => 'mfa-race@example.test', 'password' => 'test-only-password']);
        $org = new Organization;
        $org->forceFill(['id' => app(Cabinet::class)->organizationId(), 'name' => 'MFA race', 'owner_user_id' => $user->id])->save();
        $user->forceFill(['mfa_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'mfa_confirmed_at' => now()])->save();
        $user->refresh();
        $safe = MfaCode::parse('ABCDEF12-ABCDEF12-ABCDEF12-ABCDEF12');
        DB::table('mfa_recovery_codes')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->id, 'code_hash' => $safe['digest'], 'created_at' => now(), 'updated_at' => now()]);
        $input = ['pending' => ['user_id' => $user->id, 'version' => $user->auth_version, 'expires' => now()->addMinute()->timestamp], 'safe' => $safe];
        $workers = [];
        DB::beginTransaction();
        try {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            for ($i = 0; $i < 2; $i++) {
                $worker = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/recovery-race-worker.php'], base_path(), ['APP_ENV' => 'testing'], json_encode($input, JSON_THROW_ON_ERROR), 30);
                $worker->start();
                $workers[] = $worker;
            }
            $pids = [];
            $deadline = microtime(true) + 20;
            do {
                $pids = [];
                foreach ($workers as $worker) {
                    if (preg_match('/ready:(\d+)/', $worker->getOutput(), $match)) {
                        $pids[] = (int) $match[1];
                    }
                }
                if (count($pids) < 2) {
                    usleep(10000);
                }
            } while (count($pids) < 2 && microtime(true) < $deadline);
            self::assertCount(2, $pids);
            do {
                $blocked = DB::table('pg_locks')->whereIn('pid', $pids)->where('granted', false)->distinct()->count('pid');
                if ($blocked < 2) {
                    usleep(10000);
                }
            } while ($blocked < 2 && microtime(true) < $deadline);
            self::assertSame(2, $blocked);
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                self::assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $results[] = trim(preg_replace('/ready:\d+\s*/', '', $worker->getOutput()));
            }
            sort($results);
            self::assertSame(['accepted', 'denied'], $results);
            self::assertSame(1, DB::table('mfa_recovery_codes')->whereNotNull('used_at')->count());
            self::assertSame(1, DB::table('audit_logs')->where('action', 'account.mfa.recovery_code_used')->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }
}
