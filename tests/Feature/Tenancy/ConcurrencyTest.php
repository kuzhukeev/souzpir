<?php

namespace Tests\Feature\Tenancy;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Team\Models\Invitation;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Database\Seeders\Tenancy\CabinetFixtureSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('race')]
final class ConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_real_connections_can_consume_a_token_only_once(): void
    {
        app(CabinetFixtureSeeder::class)->run();
        $org = Organization::query()->firstOrFail();
        $brand = Brand::withoutGlobalScopes()->firstOrFail();
        $actor = User::create(['name' => 'Race fixture', 'email' => 'race@example.test', 'password' => 'test-only-password']);
        $actor->forceFill(['email_verified_at' => now()])->save();
        $token = bin2hex(random_bytes(32));
        app(TenantContextRunner::class)->run(new TenantContext($org->id), fn () => Invitation::create([
            'email' => $actor->email, 'role' => 'member', 'brand_ids' => [$brand->id],
            'token_hash' => hash('sha256', $token), 'invited_by' => $actor->id, 'expires_at' => now()->addDay(),
        ]));
        $processes = [];
        DB::beginTransaction();
        try {
            DB::table('organizations')->where('id', $org->id)->lockForUpdate()->first();
            for ($i = 0; $i < 2; $i++) {
                $process = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), base_path('tests/Feature/Tenancy/Fixtures/accept-worker.php')],
                    base_path(), ['APP_ENV' => 'testing', 'TENANCY_TEST_ACTOR' => $actor->id, 'TENANCY_TEST_TOKEN' => $token], timeout: 15);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            while (microtime(true) < $deadline && array_filter($processes, fn ($p) => ! str_contains($p->getOutput(), 'ready'))) {
                usleep(10000);
            }
            foreach ($processes as $process) {
                $this->assertStringContainsString('ready', $process->getOutput());
            }
            DB::commit();
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
                $results[] = trim(str_replace("ready\n", '', $process->getOutput()));
            }
            sort($results);
            $this->assertSame(['accepted', 'denied:404'], $results);
            $this->assertSame(1, Membership::withoutGlobalScopes()->where('user_id', $actor->id)->count());
            $this->assertSame(1, DB::table('invitations')->whereNotNull('accepted_at')->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }
}
