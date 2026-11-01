<?php

namespace Tests\Feature\Vision\Measurements;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\Measurements\MeasurementList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Feature\Vision\Measurements\Fixtures\IsolatedDatabaseGuard;
use Tests\TestCase;

final class MeasurementAccessRaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_E804_ACCESS_RACE') !== '1') {
            $this->markTestSkipped('Requires the dedicated E8-04 test database.');
        }
        IsolatedDatabaseGuard::check();
        Artisan::call('migrate', ['--force' => true]);
    }

    private function fixture(string $kind): array
    {
        $user = User::create(['name' => 'Race owner', 'email' => Str::lower(Str::random(16)).'@example.test', 'password' => 'test-only-password']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->refresh();
        $org = Organization::create(['name' => 'Race organization', 'owner_user_id' => $user->id]);
        [$brand, $member] = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $user->id, source: 'job'), function () use ($user): array {
            return [Brand::create(['name' => 'Race brand', 'theme' => 'neutral']), Membership::create(['user_id' => $user->id, 'role' => 'owner'])];
        });
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $plan = (string) Str::ulid();
        DB::table('plans')->insert(['id' => $plan, 'code' => Str::lower(Str::random(15)), 'name' => 'Race plan', 'created_at' => now(), 'updated_at' => now()]);
        if ($kind !== 'assignment_insert') {
            DB::table('organization_plan')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'plan_id' => $plan, 'starts_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        }
        if ($kind === 'limit_update') {
            DB::table('plan_limits')->insert(['id' => (string) Str::ulid(), 'plan_id' => $plan, 'key' => 'modules.vision', 'value' => 1, 'unit' => 'bool', 'created_at' => now(), 'updated_at' => now()]);
        } elseif ($kind === 'assignment_insert') {
            DB::table('plan_limits')->insert(['id' => (string) Str::ulid(), 'plan_id' => $plan, 'key' => 'modules.vision', 'value' => 0, 'unit' => 'bool', 'created_at' => now(), 'updated_at' => now()]);
        }
        $request = Request::create('/vision/runs/data', 'GET');
        $request->setLaravelSession(app('session')->driver());
        $request->session()->setId(Str::random(40));
        $request->session()->start();
        $request->session()->put('auth.version', $user->auth_version);
        $request->session()->put('brand_id', $brand->id);
        $request->setUserResolver(fn () => $user);
        app(AuthSessionRegistry::class)->issue($request, $user, $user->auth_version);
        $selection = app(AuthSessionRegistry::class)->withinActive($request, fn () => app(SessionBrandSelection::class)->initialize($request, $org->id, $brand->id));
        $request->attributes->set('tenant.brand_revision', (string) $selection->brand_revision);
        $context = new TenantContext($org->id, $brand->id, $user->id, $member->id);

        return [$user, $org, $brand, $member, $plan, $request, $context];
    }

    private function worker(array $fixture, string $kind, string $name): Process
    {
        [$user, $org, $brand, $member, $plan] = $fixture;
        $db = DB::connection();

        return new Process([PHP_BINARY, '-d', 'sys_temp_dir='.sys_get_temp_dir(), base_path('tests/Feature/Vision/Measurements/Fixtures/access-race-worker.php')], base_path(), [
            'APP_ENV' => 'testing', 'E804_ACCESS_RACE' => '1', 'E804_ORG' => $org->id, 'E804_BRAND' => $brand->id,
            'E804_USER' => $user->id, 'E804_MEMBER' => $member->id, 'E804_PLAN' => $plan, 'E804_KIND' => $kind, 'PGAPPNAME' => $name,
            'E804_RACE_TEST_DB' => getenv('E804_RACE_TEST_DB'), 'E804_RACE_TEST_ROLE' => getenv('E804_RACE_TEST_ROLE'),
            'E804_RACE_TEST_HOST' => getenv('E804_RACE_TEST_HOST'), 'E804_RACE_TEST_PORT' => getenv('E804_RACE_TEST_PORT'),
            'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_HOST' => $db->getConfig('host'), 'DB_PORT' => (string) $db->getConfig('port'),
            'DB_DATABASE' => $db->getDatabaseName(), 'DB_USERNAME' => $db->getConfig('username'), 'DB_PASSWORD' => $db->getConfig('password') ?? '',
            'DB_TEST_DATABASE' => config('runtime.test_database'), 'APP_PREVIEW_DATABASE' => config('runtime.preview_database'),
        ], timeout: 20);
    }

    private function waitBlocked(Process $worker, string $name): void
    {
        $worker->start();
        $deadline = microtime(true) + 12;
        while (microtime(true) < $deadline) {
            DB::statement('select pg_stat_clear_snapshot()');
            if (str_contains($worker->getOutput(), 'ready') && DB::table('pg_stat_activity')->where('application_name', $name)->where('wait_event_type', 'Lock')->exists()) {
                return;
            }
            if (! $worker->isRunning()) {
                break;
            }
            usleep(20000);
        }
        $this->fail('Worker did not block: '.$worker->getOutput().$worker->getErrorOutput());
    }

    public function test_read_holds_entitlement_locks_against_concurrent_writers(): void
    {
        foreach (['module', 'limit_update', 'limit_insert', 'assignment_insert'] as $kind) {
            $fixture = $this->fixture($kind);
            [, , , , , $request, $context] = $fixture;
            $name = 'e804-'.Str::lower(Str::random(10));
            $worker = $this->worker($fixture, $kind, $name);
            try {
                app(TenantContextRunner::class)->run($context, fn () => app(MeasurementList::class)->withinCurrent($request, function () use ($worker, $name): void {
                    $this->waitBlocked($worker, $name);
                }, false));
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $kind.': '.$worker->getOutput().$worker->getErrorOutput());
                $this->assertStringContainsString('updated', $worker->getOutput(), $kind);
                try {
                    app(TenantContextRunner::class)->run($context, fn () => app(MeasurementList::class)->withinCurrent($request, fn () => null, false));
                    $this->fail('Revoked access was accepted: '.$kind);
                } catch (HttpException $error) {
                    $this->assertSame(404, $error->getStatusCode(), $kind);
                }
            } finally {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }

    public function test_writer_first_blocks_reader_and_read_is_denied_after_commit(): void
    {
        foreach (['module', 'limit_update'] as $kind) {
            $fixture = $this->fixture($kind);
            [, $org, $brand, , $plan] = $fixture;
            $name = 'e804-'.Str::lower(Str::random(10));
            $worker = $this->worker($fixture, 'reader', $name);
            DB::beginTransaction();
            try {
                if ($kind === 'module') {
                    DB::table('brand_modules')->where('organization_id', $org->id)->where('brand_id', $brand->id)->where('module', 'vision')->update(['enabled' => false]);
                } else {
                    DB::table('plan_limits')->where('plan_id', $plan)->where('key', 'modules.vision')->update(['value' => 0]);
                }
                $this->waitBlocked($worker, $name);
                DB::commit();
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $kind.': '.$worker->getOutput().$worker->getErrorOutput());
                $this->assertStringContainsString('denied:404', $worker->getOutput(), $kind);
                $this->assertStringNotContainsString('allowed', $worker->getOutput(), $kind);
            } finally {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }
}
