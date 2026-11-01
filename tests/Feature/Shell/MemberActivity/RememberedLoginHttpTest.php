<?php

namespace Tests\Feature\Shell\MemberActivity;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\IsolatedAuthDatabase;
use Tests\TestCase;

final class RememberedLoginHttpTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        IsolatedAuthDatabase::assert();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(static function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_explicit_remember_login_counts_but_cookie_restoration_does_not(): void
    {
        $user = User::create(['name' => 'Synthetic viewer', 'email' => 'remember-e106d@example.test', 'password' => 'race-password-strong']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $org = Organization::create(['name' => 'Synthetic cabinet']);
        $brand = app(TenantContextRunner::class)->run(new TenantContext($org->id), function () use ($user): Brand {
            $brand = Brand::create(['name' => 'Synthetic brand']);
            Membership::create(['user_id' => $user->id, 'role' => 'viewer', 'brand_ids' => [$brand->id]]);

            return $brand;
        });
        $directory = base_path('.local/tmp/e106d-remember-'.Str::random(20));
        File::ensureDirectoryExists($directory);
        $base = ['directory' => $directory, 'organization' => $org->id, 'brand' => $brand->id, 'cookie' => '', 'email' => $user->email];
        $login = $this->request([...$base, 'operation' => 'login', 'remember' => true]);
        self::assertSame(302, $login['status']);
        self::assertIsString($login['recaller']);
        self::assertNotNull(DB::table('member_last_logins')->where('user_id', $user->id)->value('last_login_at'));
        DB::table('member_last_logins')->where('user_id', $user->id)->update(['last_login_at' => '2000-01-01 00:00:00+00']);
        $old = DB::table('member_last_logins')->where('user_id', $user->id)->value('last_login_at');
        $remembered = $this->request([...$base, 'operation' => 'profile', 'recaller' => $login['recaller']]);
        self::assertSame(200, $remembered['status']);
        self::assertNotSame($login['cookie'], $remembered['cookie']);
        self::assertSame(1, DB::table('auth_session_registry')->where('user_id', $user->id)->whereNotNull('remember_fingerprint')->count());
        self::assertSame($old, DB::table('member_last_logins')->where('user_id', $user->id)->value('last_login_at'));
        $again = $this->request([...$base, 'operation' => 'login', 'remember' => true]);
        self::assertSame(302, $again['status']);
        self::assertNotSame($old, DB::table('member_last_logins')->where('user_id', $user->id)->value('last_login_at'));
    }

    private function request(array $data): array
    {
        $command = [PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), base_path('tests/Feature/Shell/Profile/Fixtures/profile-selection-http-worker.php')];
        $environment = array_merge(getenv(), ['APP_ENV' => 'testing', 'SESSION_DRIVER' => 'file', 'PROFILE_SETTINGS_ENABLED' => 'true', 'CABINET_ORGANIZATION_ID' => $data['organization']]);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $environment);
        self::assertIsResource($process);
        fwrite($pipes[0], json_encode($data, JSON_THROW_ON_ERROR)."\n");
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);

        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }
}
