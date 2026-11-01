<?php

namespace Tests\Feature\Shell\Integrations;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Integrations\PruneSecretReceipts;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\MfaTestDatabase;
use Tests\TestCase;

final class SecretSharedSessionRaceTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        // Workers need committed fixtures. Fresh migrations isolate each test without
        // attempting irreversible application migration rollbacks during teardown.
        MfaTestDatabase::assert();
        $this->refreshTestDatabase();
    }

    public function test_logout_waits_for_an_already_authorized_secret_reveal(): void
    {
        $this->assertLogoutRevealRace(false);
    }

    public function test_secret_reveal_is_denied_when_logout_wins(): void
    {
        $this->assertLogoutRevealRace(true);
    }

    public function test_reveal_waiting_for_another_sessions_revoke_never_returns_the_revoked_key(): void
    {
        Http::preventStrayRequests();
        $owner = User::create(['name' => 'Race owner', 'email' => 'e110-race@example.test', 'password' => 'race-password-strong']);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $organization = Organization::create(['name' => 'Race fixture', 'owner_user_id' => $owner->id]);
        app(TenantContextRunner::class)->run(new TenantContext($organization->id, actorId: $owner->id), function () use ($owner): void {
            Membership::create(['user_id' => $owner->id, 'role' => 'owner']);
            Brand::create(['name' => 'Race brand', 'theme' => 'neutral']);
        });

        $barrier = base_path('.local/tmp/e110-revoked-'.Str::random(16));
        $revoke = $reveal = null;
        try {
            $sessions = [];
            foreach (range(1, 2) as $_) {
                $login = $this->worker('login', null, $barrier, $organization->id);
                $login->mustRun();
                $loggedIn = json_decode($login->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame(302, $loggedIn['status']);
                $select = $this->worker('select', $loggedIn['cookie'], $barrier, $organization->id);
                $select->mustRun();
                $selected = json_decode($select->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame(204, $selected['status']);
                $sessions[] = $selected['cookie'];
            }

            $create = $this->worker('create', $sessions[0], $barrier, $organization->id);
            $create->mustRun();
            $created = json_decode($create->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(302, $created['status']);
            $revoke = $this->worker('revoke-first', $sessions[1], $barrier, $organization->id);
            $revoke->start();
            $this->waitFor($barrier.'.locked');
            $reveal = $this->worker('second', $created['cookie'], $barrier, $organization->id);
            $reveal->start();
            $this->waitFor($barrier.'.second-started');
            $this->waitForDatabaseLock('e110_secret_second');
            file_put_contents($barrier.'.release', '1');
            self::assertSame(0, $revoke->wait(), $revoke->getErrorOutput());
            self::assertSame(0, $reveal->wait(), $reveal->getErrorOutput());
            self::assertSame(302, json_decode($revoke->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status']);
            self::assertSame(0, DB::table('personal_access_tokens')->count());
            $revealed = json_decode($reveal->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(404, $revealed['status']);
            self::assertFalse($revealed['has_value']);
        } finally {
            file_put_contents($barrier.'.release', '1');
            foreach ([$revoke, $reveal] as $worker) {
                if ($worker && $worker->isRunning()) {
                    $worker->wait();
                }
            }
            foreach (['.locked', '.second-started', '.release'] as $suffix) {
                if (is_file($barrier.$suffix)) {
                    unlink($barrier.$suffix);
                }
            }
        }
    }

    public function test_pruning_expired_consumed_receipt_cannot_revive_a_restored_session(): void
    {
        Http::preventStrayRequests();
        $owner = User::create(['name' => 'Race owner', 'email' => 'e110-race@example.test', 'password' => 'race-password-strong']);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $organization = Organization::create(['name' => 'Race fixture', 'owner_user_id' => $owner->id]);
        app(TenantContextRunner::class)->run(new TenantContext($organization->id, actorId: $owner->id), function () use ($owner): void {
            Membership::create(['user_id' => $owner->id, 'role' => 'owner']);
            Brand::create(['name' => 'Race brand', 'theme' => 'neutral']);
        });
        $barrier = base_path('.local/tmp/e110-replay-'.Str::random(16));
        try {
            $login = $this->worker('login', null, $barrier, $organization->id);
            $login->mustRun();
            $loginData = json_decode($login->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $select = $this->worker('select', $loginData['cookie'], $barrier, $organization->id);
            $select->mustRun();
            $selectData = json_decode($select->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $create = $this->worker('create', $selectData['cookie'], $barrier, $organization->id);
            $create->mustRun();
            $created = json_decode($create->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(302, $created['status']);
            $session = DB::table('sessions')->where('user_id', $owner->id)->first(['id', 'payload']);
            self::assertNotNull($session);
            file_put_contents($barrier.'.release', '1');
            $reveal = $this->worker('first', $created['cookie'], $barrier, $organization->id);
            $reveal->mustRun();
            $revealed = json_decode($reveal->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(200, $revealed['status']);
            self::assertTrue($revealed['has_value']);
            self::assertSame(1, DB::table('integration_secret_receipts')->whereNotNull('consumed_at')->update(['expires_at' => now()->subMinute()]));
            self::assertSame(1, app(PruneSecretReceipts::class)->prune(1));
            DB::table('sessions')->where('id', $session->id)->update(['payload' => $session->payload]);
            $retry = $this->worker('second', $created['cookie'], $barrier, $organization->id);
            $retry->mustRun();
            $retried = json_decode($retry->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(404, $retried['status']);
            self::assertFalse($retried['has_value']);
        } finally {
            foreach (['.locked', '.second-started', '.release'] as $suffix) {
                if (is_file($barrier.$suffix)) {
                    unlink($barrier.$suffix);
                }
            }
        }
    }

    private function assertLogoutRevealRace(bool $logoutFirst): void
    {
        Http::preventStrayRequests();
        $owner = User::create(['name' => 'Race owner', 'email' => 'e110-race@example.test', 'password' => 'race-password-strong']);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $organization = Organization::create(['name' => 'Race fixture', 'owner_user_id' => $owner->id]);
        app(TenantContextRunner::class)->run(new TenantContext($organization->id, actorId: $owner->id), function () use ($owner): void {
            Membership::create(['user_id' => $owner->id, 'role' => 'owner']);
            Brand::create(['name' => 'Race brand', 'theme' => 'neutral']);
        });
        $barrier = base_path('.local/tmp/e110-logout-race-'.Str::random(16));
        $first = $second = null;
        try {
            $login = $this->worker('login', null, $barrier, $organization->id);
            $login->mustRun();
            $loginResult = json_decode($login->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(302, $loginResult['status']);
            $select = $this->worker('select', $loginResult['cookie'], $barrier, $organization->id);
            $select->mustRun();
            $selected = json_decode($select->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(204, $selected['status']);
            $create = $this->worker('create', $selected['cookie'], $barrier, $organization->id);
            $create->mustRun();
            $created = json_decode($create->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(302, $created['status']);
            $first = $this->worker($logoutFirst ? 'logout-first' : 'first', $created['cookie'], $barrier, $organization->id);
            $secondMode = $logoutFirst ? 'second' : 'logout';
            $first->start();
            $this->waitFor($barrier.'.locked');
            $second = $this->worker($secondMode, $created['cookie'], $barrier, $organization->id);
            $second->start();
            $this->waitFor($barrier.'.second-started');
            $this->waitForDatabaseLock('e110_secret_'.$secondMode);
            file_put_contents($barrier.'.release', '1');
            self::assertSame(0, $first->wait(), $first->getErrorOutput());
            self::assertSame(0, $second->wait(), $second->getErrorOutput());
            $one = json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $two = json_decode($second->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($logoutFirst ? 302 : 200, $one['status']);
            self::assertSame(! $logoutFirst, $one['has_value']);
            self::assertSame($logoutFirst ? 401 : 302, $two['status']);
            self::assertFalse($two['has_value']);
            $replay = $this->worker('second', $created['cookie'], $barrier, $organization->id);
            $replay->mustRun();
            $replayed = json_decode($replay->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(401, $replayed['status']);
            self::assertFalse($replayed['has_value']);
        } finally {
            file_put_contents($barrier.'.release', '1');
            foreach ([$first, $second] as $worker) {
                if ($worker && $worker->isRunning()) {
                    $worker->wait();
                }
            }
            foreach (['.locked', '.second-started', '.release'] as $suffix) {
                if (is_file($barrier.$suffix)) {
                    unlink($barrier.$suffix);
                }
            }
        }
    }

    private function waitForDatabaseLock(string $applicationName): void
    {
        $deadline = microtime(true) + 15;
        do {
            $waiting = DB::selectOne('select count(*) as n from pg_stat_activity where datname = current_database() and application_name = ? and wait_event_type = ?', [$applicationName, 'Lock']);
            if ((int) $waiting->n > 0) {
                return;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        self::fail('The concurrent request did not reach the database lock.');
    }

    public function test_two_processes_with_shared_database_session_deliver_secret_only_once(): void
    {
        Http::preventStrayRequests();
        $owner = User::create(['name' => 'Race owner', 'email' => 'e110-race@example.test', 'password' => 'race-password-strong']);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $organization = Organization::create(['name' => 'Race fixture', 'owner_user_id' => $owner->id]);
        app(TenantContextRunner::class)->run(new TenantContext($organization->id, actorId: $owner->id), function () use ($owner): void {
            Membership::create(['user_id' => $owner->id, 'role' => 'owner']);
            Brand::create(['name' => 'Race brand', 'theme' => 'neutral']);
        });
        $barrier = base_path('.local/tmp/e110-race-'.Str::random(16));
        $first = null;
        $second = null;
        try {
            $login = $this->worker('login', null, $barrier, $organization->id);
            $login->mustRun();
            $loginResult = json_decode($login->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(302, $loginResult['status']);
            self::assertIsString($loginResult['cookie']);
            $select = $this->worker('select', $loginResult['cookie'], $barrier, $organization->id);
            $select->mustRun();
            $selected = json_decode($select->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(204, $selected['status']);
            $outer = $this->worker('outer', $selected['cookie'], $barrier, $organization->id);
            $outer->mustRun();
            self::assertSame(503, json_decode($outer->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status']);
            self::assertSame(0, DB::table('personal_access_tokens')->count());
            $create = $this->worker('create', $selected['cookie'], $barrier, $organization->id);
            $create->mustRun();
            $created = json_decode($create->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(302, $created['status']);
            $first = $this->worker('first', $created['cookie'], $barrier, $organization->id);
            $first->start();
            $this->waitFor($barrier.'.locked');
            $second = $this->worker('second', $created['cookie'], $barrier, $organization->id);
            $second->start();
            $this->waitFor($barrier.'.second-started');
            usleep(250000);
            self::assertTrue($second->isRunning(), 'The second request must wait for the first session lock.');
            file_put_contents($barrier.'.release', '1');
            self::assertSame(0, $first->wait(), $first->getErrorOutput());
            self::assertSame(0, $second->wait(), $second->getErrorOutput());
            $one = json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $two = json_decode($second->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(200, $one['status']);
            self::assertTrue($one['has_value']);
            self::assertSame(404, $two['status']);
            self::assertFalse($two['has_value']);
        } finally {
            file_put_contents($barrier.'.release', '1');
            foreach ([$first, $second] as $worker) {
                if ($worker && $worker->isRunning()) {
                    $worker->wait();
                }
            }
            foreach (['.locked', '.second-started', '.release'] as $suffix) {
                if (is_file($barrier.$suffix)) {
                    unlink($barrier.$suffix);
                }
            }
        }
    }

    private function worker(string $mode, ?string $cookie, string $barrier, string $organization): Process
    {
        return new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/secret-session-worker.php'], base_path(), [
            'APP_ENV' => 'testing', 'SESSION_DRIVER' => 'database', 'CACHE_STORE' => 'database', 'CABINET_ORGANIZATION_ID' => $organization,
            'PGAPPNAME' => 'e110_secret_'.$mode,
        ], json_encode(compact('mode', 'cookie', 'barrier'), JSON_THROW_ON_ERROR), 25);
    }

    private function waitFor(string $file): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($file) && microtime(true) < $deadline) {
            usleep(10000);
        }
        self::assertFileExists($file, 'Concurrent request did not reach the expected barrier.');
    }
}
