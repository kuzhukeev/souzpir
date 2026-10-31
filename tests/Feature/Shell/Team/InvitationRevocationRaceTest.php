<?php

namespace Tests\Feature\Shell\Team;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\InvitationExchangeGrants;
use App\Shell\Auth\SessionBrandSelection;
use App\Shell\Team\InvitationRevocation;
use App\Shell\Team\Models\Invitation;
use App\Tenancy\Invitations\InvitationAcceptance;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class InvitationRevocationRaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = DB::connection();
        $name = $db->getDatabaseName();
        $actual = DB::selectOne('select current_database() as name, host(inet_server_addr()) as host');
        if (! app()->environment('testing') || $db->getDriverName() !== 'pgsql' || ! is_string($name)
            || ! str_starts_with($name, 'souzpir_test_') || $actual->name !== $name
            || ! str_starts_with($actual->host, '127.') || config('runtime.test_database') !== $name
            || config('runtime.preview_database') === $name || realpath(app()->basePath()) !== realpath(getcwd())) {
            throw new \LogicException('Refusing race test on a non-isolated database.');
        }
        Artisan::call('migrate', ['--force' => true]);
    }

    private function fixture(): array
    {
        $suffix = Str::lower(Str::random(12));
        $owner = User::create(['name' => 'Race owner', 'email' => "race-owner-{$suffix}@example.test", 'password' => 'test-only-password']);
        $guest = User::create(['name' => 'Race invitee', 'email' => "race-guest-{$suffix}@example.test", 'password' => 'test-only-password']);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $guest->forceFill(['email_verified_at' => now()])->save();
        $owner->refresh();
        $guest->refresh();
        $org = Organization::create(['name' => 'Race test organization', 'owner_user_id' => $owner->id]);
        [$brand, $member, $invitation, $token] = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $owner->id), function () use ($owner, $guest): array {
            $brand = Brand::create(['name' => 'Race brand', 'theme' => 'neutral']);
            $member = Membership::create(['user_id' => $owner->id, 'role' => 'owner']);
            $token = Str::random(64);
            $invitation = Invitation::create(['email' => $guest->email, 'role' => 'viewer', 'brand_ids' => [$brand->id], 'token_hash' => hash('sha256', $token), 'invited_by' => $owner->id, 'expires_at' => now()->addDay()]);

            return [$brand, $member, $invitation, $token];
        });
        config(['features.E0-02.defaults.organization_id' => $org->id]);
        $session = clone app('session')->driver();
        $session->setId(Str::random(40));
        $session->start();
        $session->put('auth.version', $owner->auth_version);
        $request = Request::create('/team/invitations/test/revoke', 'POST');
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $owner);
        Auth::setUser($owner);
        app(AuthSessionRegistry::class)->issue($request, $owner, $owner->auth_version);
        $selection = app(AuthSessionRegistry::class)->withinActive($request, fn () => app(SessionBrandSelection::class)->initialize($request, $org->id, $brand->id));
        $request->attributes->set('tenant.brand_revision', (string) $selection->brand_revision);
        $context = new TenantContext($org->id, $brand->id, $owner->id, $member->id);

        return [$owner, $guest, $org, $brand, $member, $invitation, $token, $request, $context];
    }

    private function waitBlocked(Process $process, string $name): void
    {
        $process->start();
        $deadline = microtime(true) + 12;
        while (microtime(true) < $deadline) {
            DB::statement('select pg_stat_clear_snapshot()');
            $waiting = DB::table('pg_stat_activity')->where('application_name', $name)->where('wait_event_type', 'Lock')->exists();
            if (str_contains($process->getOutput(), 'ready') && $waiting) {
                return;
            }
            if (! $process->isRunning()) {
                break;
            }
            usleep(20000);
        }
        $this->fail('Worker did not block on the organization lock: '.$process->getOutput().$process->getErrorOutput());
    }

    public function test_revoke_first_blocks_concurrent_acceptance_then_acceptance_fails(): void
    {
        [, $guest, $org, , , $invitation, $token, $request, $context] = $this->fixture();
        $name = 'e106-accept-'.Str::lower(Str::random(8));
        $worker = new Process([PHP_BINARY, base_path('tests/Feature/Tenancy/Fixtures/accept-worker.php')], base_path(), [
            'TENANCY_TEST_ACTOR' => $guest->id, 'TENANCY_TEST_TOKEN' => $token, 'CABINET_ORGANIZATION_ID' => $org->id, 'PGAPPNAME' => $name,
        ], timeout: 20);
        DB::beginTransaction();
        try {
            DB::table('organizations')->where('id', $org->id)->lockForUpdate()->first();
            app(TenantContextRunner::class)->run($context, fn () => app(InvitationRevocation::class)->revoke($request, $invitation->id));
            $this->waitBlocked($worker, $name);
            DB::commit();
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('denied:404', $worker->getOutput());
            $this->assertNull(DB::table('invitations')->where('id', $invitation->id)->value('accepted_at'));
            $this->assertNotNull(DB::table('invitations')->where('id', $invitation->id)->value('revoked_at'));
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
    }

    public function test_acceptance_first_blocks_concurrent_revocation_then_revocation_fails(): void
    {
        [$owner, $guest, $org, $brand, $member, $invitation, $token, $request] = $this->fixture();
        $name = 'e106-revoke-'.Str::lower(Str::random(8));
        $worker = new Process([PHP_BINARY, base_path('tests/Feature/Shell/Team/Fixtures/revoke-race-worker.php')], base_path(), [
            'E106_TEST_DB' => DB::connection()->getDatabaseName(), 'E106_ACTOR' => $owner->id, 'E106_ORG' => $org->id,
            'E106_BRAND' => $brand->id, 'E106_MEMBERSHIP' => $member->id, 'E106_INVITATION' => $invitation->id,
            'E106_SESSION' => $request->session()->getId(), 'PGAPPNAME' => $name,
            'E106_REVISION' => $request->attributes->get('tenant.brand_revision'),
        ], timeout: 20);
        DB::beginTransaction();
        try {
            DB::table('organizations')->where('id', $org->id)->lockForUpdate()->first();
            app(InvitationAcceptance::class)->accept($guest, $token);
            $this->waitBlocked($worker, $name);
            DB::commit();
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('denied:404', $worker->getOutput());
            $this->assertNotNull(DB::table('invitations')->where('id', $invitation->id)->value('accepted_at'));
            $this->assertNull(DB::table('invitations')->where('id', $invitation->id)->value('revoked_at'));
            $this->assertSame(0, DB::table('audit_logs')->where('action', 'team.invitation.revoked')->where('subject_id', $invitation->id)->count());
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
    }

    public function test_revoke_first_blocks_concurrent_exchange_then_exchange_fails(): void
    {
        [, , $org, , , $invitation, $token, $request, $context] = $this->fixture();
        $name = 'e106-exchange-'.Str::lower(Str::random(8));
        $worker = new Process([PHP_BINARY, base_path('tests/Feature/Shell/Team/Fixtures/exchange-race-worker.php')], base_path(), [
            'E106_TEST_DB' => DB::connection()->getDatabaseName(), 'E106_TOKEN_HASH' => hash('sha256', $token),
            'CABINET_ORGANIZATION_ID' => $org->id, 'PGAPPNAME' => $name,
        ], timeout: 20);
        DB::beginTransaction();
        try {
            DB::table('organizations')->where('id', $org->id)->lockForUpdate()->first();
            app(TenantContextRunner::class)->run($context, fn () => app(InvitationRevocation::class)->revoke($request, $invitation->id));
            $this->waitBlocked($worker, $name);
            DB::commit();
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('denied', $worker->getOutput());
            $this->assertNull(DB::table('invitations')->where('id', $invitation->id)->value('exchanged_at'));
            $this->assertNotNull(DB::table('invitations')->where('id', $invitation->id)->value('revoked_at'));
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
    }

    public function test_exchange_first_then_revocation_invalidates_the_session_grant(): void
    {
        [$owner, $guest, $org, $brand, $member, $invitation, $token, $ownerRequest] = $this->fixture();
        $session = app('session')->driver();
        $session->setId(Str::random(40));
        $session->start();
        $request = Request::create('/invitations/exchange', 'POST');
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $guest);
        $exchange = app(InvitationExchangeGrants::class);
        $epoch = $exchange->beginDocument($request);
        $name = 'e106-revoke-'.Str::lower(Str::random(8));
        $worker = new Process([PHP_BINARY, base_path('tests/Feature/Shell/Team/Fixtures/revoke-race-worker.php')], base_path(), [
            'E106_TEST_DB' => DB::connection()->getDatabaseName(), 'E106_ACTOR' => $owner->id, 'E106_ORG' => $org->id,
            'E106_BRAND' => $brand->id, 'E106_MEMBERSHIP' => $member->id, 'E106_INVITATION' => $invitation->id,
            'E106_SESSION' => $ownerRequest->session()->getId(), 'PGAPPNAME' => $name,
            'E106_REVISION' => $ownerRequest->attributes->get('tenant.brand_revision'),
        ], timeout: 20);
        DB::beginTransaction();
        try {
            DB::table('organizations')->where('id', $org->id)->lockForUpdate()->first();
            $this->assertTrue($exchange->exchange($request, hash('sha256', $token), $epoch, 1));
            $this->waitBlocked($worker, $name);
            DB::commit();
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('revoked', $worker->getOutput());
            $this->assertNotNull(DB::table('invitations')->where('id', $invitation->id)->value('revoked_at'));
            try {
                app(InvitationAcceptance::class)->acceptSession($guest, $request);
                $this->fail('Revoked session grant was accepted.');
            } catch (HttpException $exception) {
                $this->assertSame(404, $exception->getStatusCode());
            }
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
    }
}
