<?php

namespace Tests\Feature\Shell\Profile;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Profile\Security\SecuritySettings;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('race')]
final class SecurityConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        self::assertIsolatedTestDatabase();
    }

    public static function modes(): array
    {
        return [['one'], ['all']];
    }

    #[DataProvider('modes')]
    public function test_two_devices_cannot_deadlock_or_undo_each_others_revocation(string $mode): void
    {
        $org = Organization::create(['name' => 'Concurrent security']);
        $user = User::create(['name' => 'Actor', 'email' => 'concurrent-security@example.test', 'password' => 'fake-fixture-password']);
        $user->forceFill(['email_verified_at' => now()])->save();
        [$brand, $member] = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $user->id), function () use ($user): array {
            $brand = Brand::create(['name' => 'Concurrent brand']);

            return [$brand, Membership::create(['user_id' => $user->id, 'role' => 'owner'])];
        });
        $first = Str::random(40);
        $second = Str::random(40);
        $firstHash = hash_hmac('sha256', 'auth-session:'.$first, (string) config('app.key'));
        $secondHash = hash_hmac('sha256', 'auth-session:'.$second, (string) config('app.key'));
        foreach ([$firstHash, $secondHash] as $hash) {
            DB::table('auth_session_registry')->insert([
                'session_hash' => $hash, 'user_id' => $user->id, 'auth_version' => 0,
                'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $common = ['organization' => $org->id, 'brand' => $brand->id, 'user' => $user->id, 'membership' => $member->id, 'mode' => $mode];
        $a = $b = null;
        try {
            $a = $this->start([...$common, 'session' => $first, 'target' => app(SecuritySettings::class)->publicId($secondHash), 'hold' => true]);
            self::assertSame("locked\n", fgets($a[1][1]));
            $b = $this->start([...$common, 'session' => $second, 'target' => app(SecuritySettings::class)->publicId($firstHash), 'hold' => false]);
            self::assertSame("started\n", fgets($b[1][1]));
            usleep(150000);
            self::assertTrue(proc_get_status($b[0])['running'], 'Second device must wait for the held ordered session locks.');
            fwrite($a[1][0], "continue\n");
            fflush($a[1][0]);
            self::assertSame("applied\n", fgets($a[1][1]));
            self::assertSame("revoked\n", fgets($b[1][1]));
            self::assertNull(DB::table('auth_session_registry')->where('session_hash', $firstHash)->value('revoked_at'));
            self::assertNotNull(DB::table('auth_session_registry')->where('session_hash', $secondHash)->value('revoked_at'));
            self::assertSame(1, DB::table('audit_logs')->whereIn('action', ['account.session.revoked', 'account.sessions.revoked'])->count());
        } finally {
            foreach ([$a, $b] as $worker) {
                if ($worker === null) {
                    continue;
                }
                [$process, $pipes] = $worker;
                if (is_resource($pipes[0])) {
                    @fwrite($pipes[0], "continue\n");
                    fclose($pipes[0]);
                }
                foreach ([1, 2] as $index) {
                    fclose($pipes[$index]);
                }
                if (proc_get_status($process)['running']) {
                    proc_terminate($process);
                }
                proc_close($process);
            }
        }
    }

    private function start(array $payload): array
    {
        $command = [PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/security-worker.php'];
        $env = array_merge(getenv(), ['APP_ENV' => 'testing', 'CABINET_ORGANIZATION_ID' => $payload['organization']]);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        self::assertIsResource($process);
        stream_set_timeout($pipes[1], 15);
        fwrite($pipes[0], json_encode($payload, JSON_THROW_ON_ERROR)."\n");
        fflush($pipes[0]);

        return [$process, $pipes];
    }
}
