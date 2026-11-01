<?php

namespace Tests\Feature\Shell\InvitationIssuance;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Shell\InvitationIssuance\InvitationIssuer;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\IsolatedAuthDatabase;
use Tests\TestCase;

final class InvitationIssuanceRaceTest extends TestCase
{
    public function test_competing_attempt_returns_conflict_after_first_transaction_commits(): void
    {
        IsolatedAuthDatabase::assert();
        self::assertSame('souzpir_test_sp_e1_06b', DB::connection()->getDatabaseName());
        self::assertSame(realpath(getcwd()), realpath(app()->basePath()));
        Artisan::call('migrate', ['--force' => true]);
        $owner = User::create(['name' => 'Race owner', 'email' => Str::lower(Str::random(12)).'@example.test', 'password' => 'test-only-password']);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $owner->refresh();
        $organization = Organization::create(['name' => 'Race organization', 'owner_user_id' => $owner->id]);
        [$brand, $membership] = app(TenantContextRunner::class)->run(new TenantContext($organization->id, actorId: $owner->id), function () use ($owner): array {
            return [Brand::create(['name' => 'Race brand', 'theme' => 'neutral']), Membership::create(['user_id' => $owner->id, 'role' => 'owner'])];
        });
        config(['features.E0-02.defaults.organization_id' => $organization->id]);
        $context = new TenantContext($organization->id, $brand->id, $owner->id, $membership->id);
        $first = $this->sessionRequest($owner, $organization->id, $brand->id);
        $second = $this->sessionRequest($owner, $organization->id, $brand->id);
        $data = ['attempt_key' => bin2hex(random_bytes(32)), 'email' => 'race-invitee@example.test', 'role' => 'viewer',
            'brand_ids' => [$brand->id], 'sales_manager' => false, 'expires_at' => now()->addDay()->format('Y-m-d\TH:i:sP')];
        $name = 'e106b-race-'.Str::lower(Str::random(8));
        $worker = new Process([PHP_BINARY, base_path('tests/Feature/Shell/InvitationIssuance/Fixtures/issue-worker.php')], base_path(), [
            'APP_ENV' => 'testing', 'E106B_DB' => DB::connection()->getDatabaseName(), 'E106B_USER' => $owner->id,
            'E106B_ORG' => $organization->id, 'E106B_BRAND' => $brand->id, 'E106B_MEMBERSHIP' => $membership->id,
            'E106B_SESSION' => $second->session()->getId(), 'E106B_REVISION' => $second->attributes->get('tenant.brand_revision'),
            'E106B_HEADER' => $second->header('X-Brand-Revision'), 'E106B_ATTEMPT' => $data['attempt_key'],
            'E106B_EXPIRES' => $data['expires_at'], 'PGAPPNAME' => $name,
        ], timeout: 25);
        DB::beginTransaction();
        try {
            $link = app(TenantContextRunner::class)->run($context, fn () => app(InvitationIssuer::class)->issue($first, $data));
            self::assertStringContainsString('/invitations/open#', $link);
            $worker->start();
            $deadline = microtime(true) + 12;
            $blocked = false;
            while (microtime(true) < $deadline && $worker->isRunning()) {
                DB::statement('select pg_stat_clear_snapshot()');
                if (str_contains($worker->getOutput(), 'ready') && DB::table('pg_stat_activity')->where('application_name', $name)->where('wait_event_type', 'Lock')->exists()) {
                    $blocked = true;
                    break;
                }
                usleep(20000);
            }
            self::assertTrue($blocked, 'The competing process must be blocked before the first transaction commits.');
            DB::commit();
            $worker->wait();
            self::assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            self::assertStringContainsString('denied:409', $worker->getOutput());
            self::assertStringNotContainsString($link, $worker->getOutput());
            self::assertSame(1, DB::table('invitations')->where('organization_id', $organization->id)->count());
            self::assertSame(1, DB::table('invitation_issuance_attempts')->where('organization_id', $organization->id)->count());
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
    }

    private function sessionRequest(User $user, string $organization, string $brand): Request
    {
        $session = clone app('session')->driver();
        $session->setId(Str::random(40));
        $session->start();
        $session->put('auth.version', $user->auth_version);
        $request = Request::create('/team/invitations', 'POST');
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $user);
        Auth::setUser($user);
        app(AuthSessionRegistry::class)->issue($request, $user, $user->auth_version);
        $selection = app(AuthSessionRegistry::class)->withinActive($request, fn () => app(SessionBrandSelection::class)->initialize($request, $organization, $brand));
        $revision = (string) $selection->brand_revision;
        $request->attributes->set('tenant.brand_revision', $revision);
        $request->headers->set('X-Brand-Revision', app(SessionBrandSelection::class)->profileRevision($request, $revision));

        return $request;
    }
}
