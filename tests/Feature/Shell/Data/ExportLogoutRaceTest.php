<?php

namespace Tests\Feature\Shell\Data;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('race')]
final class ExportLogoutRaceTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        $expected = config('runtime.test_database');
        if (! app()->environment('testing') || config('database.default') !== 'pgsql'
            || ! is_string($expected) || ! preg_match('/(?:^|_)test(?:_|$)/i', $expected)
            || DB::connection()->getDatabaseName() !== $expected
            || config('runtime.preview_database') === $expected) {
            throw new \LogicException('Isolated test database required.');
        }
    }

    public function test_logout_does_not_wait_for_blocked_download_and_stops_subsequent_chunks(): void
    {
        $user = User::create(['name' => 'Synthetic', 'email' => Str::random(16).'@example.test', 'password' => 'synthetic-password']);
        $user->refresh();
        $org = Organization::create(['name' => 'Fixture', 'owner_user_id' => $user->id]);
        [$member, $brand] = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $user->id), fn () => [
            Membership::create(['user_id' => $user->id, 'role' => 'owner']), Brand::create(['name' => 'Fixture brand']),
        ]);
        $session = app('session')->driver('array');
        $session->setId(Str::random(40));
        $session->start();
        $session->put('auth.version', $user->auth_version);
        $request = Request::create('/settings/data', 'GET');
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $user);
        app(AuthSessionRegistry::class)->issue($request, $user, $user->auth_version);
        $id = (string) Str::ulid();
        $path = 'data-exports/'.$org->id.'/'.$id.'.zip';
        $content = str_repeat('synthetic-block-', 1024 * 1024);
        Storage::disk('local')->put($path, $content);
        DB::table('data_exports')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'user_id' => $user->id, 'module' => 'seller', 'period_from' => '2026-01-01', 'period_to' => '2026-01-02',
            'path' => $path, 'sha256' => hash('sha256', $content), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        $markerDir = base_path('.local/tmp/export-race-'.Str::lower(Str::random(12)));
        mkdir($markerDir, 0770, true);
        $data = ['user' => $user->id, 'org' => $org->id, 'brand' => $brand->id, 'member' => $member->id,
            'session' => $session->getId(), 'version' => $user->auth_version, 'export' => $id, 'database' => DB::connection()->getDatabaseName()];
        $download = $logout = $logoutInput = null;
        $pipes = [];
        try {
            $download = proc_open([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/export-stream-worker.php'],
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path(), array_merge(getenv(), ['APP_ENV' => 'testing']), ['bypass_shell' => true]);
            self::assertIsResource($download);
            fwrite($pipes[0], json_encode($data + ['mode' => 'stream', 'marker' => $markerDir.'/stream'], JSON_THROW_ON_ERROR)."\n");
            fclose($pipes[0]);
            $this->waitFile($markerDir.'/stream');
            // Do not read stdout: a slow client fills the OS pipe during transmission.
            usleep(500000);
            [$logout, $logoutInput] = $this->worker($data + ['mode' => 'logout', 'marker' => $markerDir.'/logout']);
            $this->waitFile($markerDir.'/logout', $logout);
            // Logout must finish while the download pipe is still blocked and unread.
            $logout->setTimeout(10);
            $logout->wait();
            self::assertSame(0, $logout->getExitCode(), $logout->getErrorOutput());
            self::assertNotNull(DB::table('auth_session_registry')->where('user_id', $user->id)->value('revoked_at'));
            $received = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $pipes = [];
            self::assertSame(0, proc_close($download), $errors);
            $download = null;
            self::assertNotSame('', $received);
            self::assertLessThan(strlen($content), strlen($received));
            self::assertSame(substr($content, 0, strlen($received)), $received);
            self::assertNotNull(DB::table('auth_session_registry')->where('user_id', $user->id)->value('revoked_at'));
        } finally {
            if (is_resource($download)) {
                proc_terminate($download);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                } proc_close($download);
            }
            if ($logout && $logout->isRunning()) {
                $logout->stop();
            }
            if ($logoutInput) {
                $logoutInput->close();
            }
            @unlink($markerDir.'/stream');
            @unlink($markerDir.'/logout');
            @rmdir($markerDir);
            Storage::disk('local')->delete($path);
        }
    }

    private function worker(array $data): array
    {
        $input = new InputStream;
        $process = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/export-stream-worker.php'], base_path(), array_merge(getenv(), ['APP_ENV' => 'testing']), $input, 45);
        $process->start();
        $input->write(json_encode($data, JSON_THROW_ON_ERROR)."\n");

        return [$process, $input];
    }

    private function waitFile(string $path, ?Process $process = null): void
    {
        $deadline = microtime(true) + 20;
        while (! is_file($path) && ($process === null || $process->isRunning()) && microtime(true) < $deadline) {
            usleep(10000);
        }
        self::assertFileExists($path, 'Worker did not reach barrier: '.($process ? $process->getErrorOutput().$process->getOutput() : ''));
    }
}
