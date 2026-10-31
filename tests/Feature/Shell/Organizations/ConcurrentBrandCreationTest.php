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

final class ConcurrentBrandCreationTest extends TestCase
{
    public function test_concurrent_creations_obey_the_same_organization_limit(): void
    {
        $db = DB::connection()->getDatabaseName();
        self::assertTrue(app()->environment('testing') && $db === config('runtime.test_database') && $db !== config('runtime.preview_database') && str_contains($db, '_test_'));
        Artisan::call('migrate', ['--force' => true]);
        $user = User::create(['name' => 'E104 concurrency fixture', 'email' => 'e104-'.Str::lower(Str::random(20)).'@example.test', 'password' => 'test-only-password']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $org = Organization::create(['name' => 'E104 concurrency fixture']);
        [$member, $brand] = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $user->id, source: 'job'), fn () => [Membership::create(['user_id' => $user->id, 'role' => 'owner']), Brand::create(['name' => 'Existing'])]);
        $org->forceFill(['owner_user_id' => $user->id])->save();
        $plan = (string) Str::ulid();
        DB::table('plans')->insert(['id' => $plan, 'code' => 'e104-'.Str::lower(Str::random(12)), 'name' => 'Fixture', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organization_plan')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'plan_id' => $plan, 'starts_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('plan_limits')->insert(['id' => (string) Str::ulid(), 'plan_id' => $plan, 'key' => 'brands.max', 'value' => '2', 'unit' => '', 'created_at' => now(), 'updated_at' => now()]);
        $workers = [];
        try {
            DB::beginTransaction();
            DB::table('organizations')->where('id', $org->id)->lockForUpdate()->first();
            foreach (['First', 'Second'] as $name) {
                $worker = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/create-worker.php', $org->id, $brand->id, $user->id, $member->id, $name], base_path(), ['APP_ENV' => 'testing'], timeout: 30);
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
            self::assertSame(2, $blocked, 'Both database processes must wait on the organization lock.');
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                self::assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $results[] = trim(preg_replace('/ready:\d+\s*/', '', $worker->getOutput()));
            }
            sort($results);
            self::assertSame(['created', 'limited'], $results);
            self::assertSame(2, DB::table('brands')->where('organization_id', $org->id)->where('status', 'active')->count());
            self::assertSame(1, DB::table('audit_logs')->where('organization_id', $org->id)->where('action', 'brand.created')->count());
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
            DB::table('organization_plan')->where('organization_id', $org->id)->delete();
            DB::table('organizations')->where('id', $org->id)->delete();
            DB::table('plan_limits')->where('plan_id', $plan)->delete();
            DB::table('plans')->where('id', $plan)->delete();
            DB::table('users')->where('id', $user->id)->delete();
        }
    }
}
