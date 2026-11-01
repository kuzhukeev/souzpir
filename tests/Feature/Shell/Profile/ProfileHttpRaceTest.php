<?php

namespace Tests\Feature\Shell\Profile;

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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\IsolatedAuthDatabase;
use Tests\TestCase;

#[Group('race')]
final class ProfileHttpRaceTest extends TestCase
{
    use DatabaseMigrations;

    /** Recreate only the guarded disposable database; never roll back production-only migrations. */
    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(static function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function beforeRefreshingDatabase(): void
    {
        IsolatedAuthDatabase::assert();
    }

    public static function cases(): array
    {
        $cases = [];
        foreach (['get', 'put'] as $action) {
            foreach (['switch', 'logout'] as $other) {
                foreach (['profile', 'other'] as $first) {
                    $cases["$action-$other-$first-first"] = [$action, $other, $first];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('cases')]
    public function test_profile_http_and_selection_or_logout_are_serialized(string $action, string $other, string $first): void
    {
        $viewer = User::create(['name' => 'Private profile marker', 'email' => 'profile-race@example.test', 'password' => 'race-password-strong']);
        $viewer->forceFill(['email_verified_at' => now()])->save();
        $org = Organization::create(['name' => 'Profile race']);
        [$a, $b] = app(TenantContextRunner::class)->run(new TenantContext($org->id), function () use ($viewer): array {
            $a = Brand::create(['name' => 'A']);
            $b = Brand::create(['name' => 'B']);
            Membership::create(['user_id' => $viewer->id, 'role' => 'viewer', 'brand_ids' => [$a->id, $b->id]]);

            return [$a, $b];
        });
        $directory = base_path('.local/tmp/profile-http-race-'.Str::random(20));
        File::ensureDirectoryExists($directory);
        $processes = [];
        try {
            $payload = ['directory' => $directory, 'organization' => $org->id, 'brand' => $a->id, 'cookie' => '', 'hold' => '', 'worker' => 'setup'];
            $login = $this->finish($this->start([...$payload, 'operation' => 'login']));
            self::assertSame(302, $login['status']);
            self::assertIsString($login['cookie']);
            $payload['cookie'] = $login['cookie'];
            $selected = $this->finish($this->start([...$payload, 'operation' => 'select']));
            self::assertSame(302, $selected['status']);
            $payload['cookie'] = $selected['cookie'] ?? $payload['cookie'];
            $page = $this->finish($this->start([...$payload, 'operation' => 'get']));
            self::assertSame(200, $page['status']);
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $page['revision']);
            $payload['revision'] = (string) $page['revision'];
            $payload['cookie'] = $page['cookie'] ?? $payload['cookie'];

            if ($first === 'other') {
                // Pause after the old browser session has been loaded, before authorization.
                $processes['profile'] = $this->start([...$payload, 'operation' => $action, 'hold' => 'loaded', 'worker' => 'profile']);
                $this->waitFile($directory.'/held');
                $otherResult = $this->finish($this->start([...$payload, 'operation' => $other, 'brand' => $b->id, 'worker' => 'other']));
                self::assertSame(302, $otherResult['status']);
                file_put_contents($directory.'/release', '1');
                $profileResult = $this->finish($processes['profile']);
                unset($processes['profile']);
                if ($other === 'switch') {
                    self::assertSame(409, $profileResult['status'], 'The old A request must not run after switching to B.');
                    $probe = $this->finish($this->start([...$payload, 'operation' => $action, 'worker' => 'probe']));
                    self::assertSame(409, $probe['status'], 'A stale revision must fail even when the old request starts after the switch.');
                    self::assertStringNotContainsString('Private profile marker', $probe['body']);
                } else {
                    self::assertContains($profileResult['status'], [302, 401, 403], 'The old request must not run after logout.');
                }
                self::assertStringNotContainsString('Private profile marker', $profileResult['body']);
                self::assertSame('Private profile marker', $viewer->fresh()->name);
                self::assertSame(0, DB::table('audit_logs')->where('action', 'profile.updated')->where('actor_id', $viewer->id)->count());
            } else {
                // Hold the actual profile boundary's lease lock, not just middleware.
                $processes['profile'] = $this->start([...$payload, 'operation' => $action, 'hold' => 'lock', 'worker' => 'profile']);
                $this->waitFile($directory.'/held');
                $processes['other'] = $this->start([...$payload, 'operation' => $other, 'brand' => $b->id, 'worker' => 'other']);
                $this->waitFile($directory.'/pid-other');
                $pid = (int) file_get_contents($directory.'/pid-other');
                $deadline = microtime(true) + 10;
                do {
                    $blocked = DB::table('pg_locks')->where('pid', $pid)->where('granted', false)->exists();
                    if (! $blocked) {
                        usleep(10000);
                    }
                } while (! $blocked && microtime(true) < $deadline);
                self::assertTrue($blocked, 'The competing HTTP request must wait for the profile transaction.');
                file_put_contents($directory.'/release', '1');
                $profileResult = $this->finish($processes['profile']);
                unset($processes['profile']);
                $otherResult = $this->finish($processes['other']);
                unset($processes['other']);
                self::assertSame($action === 'get' ? 200 : 302, $profileResult['status']);
                self::assertSame(302, $otherResult['status']);
                self::assertSame($action === 'put' ? 'Race saved' : 'Private profile marker', $viewer->fresh()->name);
                self::assertSame($action === 'put' ? 1 : 0, DB::table('audit_logs')->where('action', 'profile.updated')->where('actor_id', $viewer->id)->where('brand_id', $a->id)->count());
            }
        } finally {
            file_put_contents($directory.'/release', '1');
            foreach ($processes as $process) {
                $this->stop($process);
            }
            File::deleteDirectory($directory);
        }
    }

    public function test_token_from_an_old_lease_cannot_authorize_a_new_lease_with_the_same_internal_revision(): void
    {
        $viewer = User::create(['name' => 'Private profile marker', 'email' => 'profile-race@example.test', 'password' => 'race-password-strong']);
        $viewer->forceFill(['email_verified_at' => now()])->save();
        $org = Organization::create(['name' => 'Profile race']);
        $brand = app(TenantContextRunner::class)->run(new TenantContext($org->id), function () use ($viewer): Brand {
            $brand = Brand::create(['name' => 'A']);
            Membership::create(['user_id' => $viewer->id, 'role' => 'viewer', 'brand_ids' => [$brand->id]]);

            return $brand;
        });
        $directory = base_path('.local/tmp/profile-http-race-'.Str::random(20));
        File::ensureDirectoryExists($directory);
        try {
            $base = ['directory' => $directory, 'organization' => $org->id, 'brand' => $brand->id, 'cookie' => '', 'hold' => '', 'worker' => 'setup'];
            $loginA = $this->finish($this->start([...$base, 'operation' => 'login']));
            self::assertSame(302, $loginA['status']);
            self::assertIsString($loginA['cookie']);
            $sessionA = [...$base, 'cookie' => $loginA['cookie']];
            $pageA = $this->finish($this->start([...$sessionA, 'operation' => 'get']));
            self::assertSame(200, $pageA['status']);
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $pageA['revision']);

            // Login without A's cookie creates a second actual browser session/lease.
            $loginB = $this->finish($this->start([...$base, 'operation' => 'login']));
            self::assertSame(302, $loginB['status']);
            self::assertIsString($loginB['cookie']);
            $sessionB = [...$base, 'cookie' => $loginB['cookie']];
            $pageB = $this->finish($this->start([...$sessionB, 'operation' => 'get']));
            self::assertSame(200, $pageB['status']);
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $pageB['revision']);
            self::assertNotSame($pageA['revision'], $pageB['revision']);
            self::assertSame(2, DB::table('auth_session_registry')->where('user_id', $viewer->id)->where('brand_revision', 0)->where('selected_brand_id', $brand->id)->whereNull('revoked_at')->count());

            $stale = $this->finish($this->start([...$sessionB, 'operation' => 'put', 'revision' => $pageA['revision']]));
            self::assertSame(409, $stale['status']);
            self::assertSame('Private profile marker', $viewer->fresh()->name);
            self::assertSame(0, DB::table('audit_logs')->where('action', 'profile.updated')->where('actor_id', $viewer->id)->count());
            $valid = $this->finish($this->start([...$sessionB, 'operation' => 'put', 'revision' => $pageB['revision']]));
            self::assertSame(302, $valid['status']);
            self::assertSame('Race saved', $viewer->fresh()->name);
            self::assertSame(1, DB::table('audit_logs')->where('action', 'profile.updated')->where('actor_id', $viewer->id)->count());
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private function start(array $data): array
    {
        $command = [PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/profile-http-race-worker.php'];
        $environment = array_merge(getenv(), ['APP_ENV' => 'testing', 'SESSION_DRIVER' => 'file', 'PROFILE_SETTINGS_ENABLED' => 'true', 'CABINET_ORGANIZATION_ID' => $data['organization']]);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $environment);
        self::assertIsResource($process);
        fwrite($pipes[0], json_encode($data, JSON_THROW_ON_ERROR)."\n");
        fclose($pipes[0]);

        return [$process, $pipes];
    }

    private function finish(array $worker): array
    {
        [$process, $pipes] = $worker;
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);

        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }

    private function stop(array $worker): void
    {
        [$process, $pipes] = $worker;
        if (proc_get_status($process)['running']) {
            proc_terminate($process);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }

    private function waitFile(string $path): void
    {
        $deadline = microtime(true) + 20;
        while (! is_file($path) && microtime(true) < $deadline) {
            usleep(10000);
        }
        self::assertFileExists($path);
    }
}
