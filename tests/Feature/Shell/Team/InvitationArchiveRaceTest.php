<?php

namespace Tests\Feature\Shell\Team;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Shell\Organizations\Http\OrganizationController;
use App\Shell\Team\InvitationRevocation;
use App\Shell\Team\Models\Invitation;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\IsolatedDatabaseMigrations;
use Tests\TestCase;

final class InvitationArchiveRaceTest extends TestCase
{
    use IsolatedDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::fake();
    }

    private function fixture(): array
    {
        $owner = User::create(['name' => 'Synthetic owner', 'email' => Str::lower(Str::random(16)).'@example.test', 'password' => 'test-only-password']);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $owner->refresh();
        $org = Organization::create(['name' => 'Synthetic organization', 'owner_user_id' => $owner->id]);
        [$member, $selected, $target, $invitation] = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $owner->id), function () use ($owner): array {
            $member = Membership::create(['user_id' => $owner->id, 'role' => 'owner']);
            $selected = Brand::create(['name' => 'Selected brand', 'theme' => 'neutral']);
            $target = Brand::create(['name' => 'Archivable brand', 'theme' => 'neutral']);
            $invitation = Invitation::create([
                'email' => 'synthetic-invite@example.test', 'role' => 'viewer', 'brand_ids' => [$target->id],
                'token_hash' => hash('sha256', Str::random(64)), 'invited_by' => $owner->id, 'expires_at' => now()->addDay(),
            ]);

            return [$member, $selected, $target, $invitation];
        });
        config(['features.E0-02.defaults.organization_id' => $org->id]);
        $context = new TenantContext($org->id, $selected->id, $owner->id, $member->id);

        return [$owner, $org, $member, $selected, $target, $invitation, $context];
    }

    private function request(User $actor, Organization $org, Membership $member, Brand $selected, string $mode, Brand $target): Request
    {
        $session = clone app('session')->driver();
        $session->setId(Str::random(40));
        $session->start();
        $session->put('auth.version', $actor->auth_version);
        $request = $mode === 'archive'
            ? Request::create('/brands/test/archive', 'PATCH', ['confirm_name' => $target->name])
            : Request::create('/team/invitations/test/revoke', 'POST');
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $actor);
        Auth::setUser($actor);
        app(AuthSessionRegistry::class)->issue($request, $actor, $actor->auth_version);
        $selection = app(AuthSessionRegistry::class)->withinActive($request, fn () => app(SessionBrandSelection::class)->initialize($request, $org->id, $selected->id));
        $request->attributes->set('tenant.brand_revision', (string) $selection->brand_revision);
        $request->headers->set('X-Brand-Revision', app(SessionBrandSelection::class)->profileRevision($request, (string) $selection->brand_revision));

        return $request;
    }

    private function admin(Organization $org, User $owner): array
    {
        $admin = User::create(['name' => 'Synthetic admin', 'email' => Str::lower(Str::random(16)).'@example.test', 'password' => 'test-only-password']);
        $admin->forceFill(['email_verified_at' => now()])->save();
        $admin->refresh();
        $membership = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $owner->id), fn () => Membership::create(['user_id' => $admin->id, 'role' => 'admin']));

        return [$admin, $membership];
    }

    private function worker(string $mode, string $name, User $actor, Organization $org, Membership $member, Brand $selected, Brand $target, Invitation $invitation, Request $request): Process
    {
        return new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), base_path('tests/Feature/Shell/Team/Fixtures/archive-revoke-worker.php')], base_path(), [
            'APP_ENV' => 'testing', 'E106_TEST_DB' => DB::connection()->getDatabaseName(),
            'E106_TEST_ROLE' => DB::connection()->getConfig('username'), 'E106_TEST_PORT' => (string) DB::connection()->getConfig('port'),
            'E106_MODE' => $mode, 'E106_ACTOR' => $actor->id, 'E106_ORG' => $org->id, 'E106_MEMBERSHIP' => $member->id,
            'E106_SELECTED_BRAND' => $selected->id, 'E106_TARGET_BRAND' => $target->id, 'E106_BRAND_NAME' => $target->name,
            'E106_INVITATION' => $invitation->id, 'E106_SESSION' => $request->session()->getId(),
            'E106_REVISION' => $request->attributes->get('tenant.brand_revision'), 'E106_HEADER_REVISION' => $request->header('X-Brand-Revision'),
            'CABINET_ORGANIZATION_ID' => $org->id, 'PGAPPNAME' => $name,
        ], timeout: 20);
    }

    private function waitBlocked(Process $worker, string $name): void
    {
        $parentPid = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
        $worker->start();
        $deadline = microtime(true) + 12;
        while (microtime(true) < $deadline) {
            DB::statement('select pg_stat_clear_snapshot()');
            $waiting = DB::selectOne('select ?::integer = any(pg_blocking_pids(pid)) as blocked, query from pg_stat_activity where application_name = ?', [$parentPid, $name]);
            if (str_contains($worker->getOutput(), 'ready') && $waiting && $waiting->blocked) {
                $this->assertStringContainsString('"organizations"', $waiting->query);

                return;
            }
            if (! $worker->isRunning()) {
                break;
            }
            usleep(20000);
        }
        $this->fail('Concurrent operation did not wait for this transaction: '.$worker->getOutput().$worker->getErrorOutput());
    }

    private function assertAudit(Invitation $invitation, Brand $target, int $revokes, int $archives): void
    {
        $this->assertSame($revokes, DB::table('audit_logs')->where('action', 'team.invitation.revoked')->where('subject_id', $invitation->id)->count());
        $this->assertSame($archives, DB::table('audit_logs')->where('action', 'brand.archived')->where('subject_id', $target->id)->count());
        $audit = json_encode(DB::table('audit_logs')->whereIn('subject_id', [$invitation->id, $target->id])->get(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($invitation->token_hash, $audit);
        $this->assertStringNotContainsString($invitation->email, $audit);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_revocation_first_serializes_before_brand_archive(): void
    {
        [$owner, $org, $member, $selected, $target, $invitation, $context] = $this->fixture();
        [$admin, $adminMember] = $this->admin($org, $owner);
        $revoke = $this->request($owner, $org, $member, $selected, 'revoke', $target);
        $archive = $this->request($admin, $org, $adminMember, $selected, 'archive', $target);
        $name = 'e106-archive-'.Str::lower(Str::random(8));
        $worker = $this->worker('archive', $name, $admin, $org, $adminMember, $selected, $target, $invitation, $archive);
        Auth::setUser($owner);
        DB::beginTransaction();
        try {
            app(TenantContextRunner::class)->run($context, fn () => app(InvitationRevocation::class)->revoke($revoke, $invitation->id));
            $this->waitBlocked($worker, $name);
            DB::commit();
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('archived', $worker->getOutput());
            $this->assertNotNull(DB::table('invitations')->where('id', $invitation->id)->value('revoked_at'));
            $this->assertSame('archived', DB::table('brands')->where('id', $target->id)->value('status'));
            $this->assertAudit($invitation, $target, 1, 1);
            $this->assertSame($owner->id, DB::table('audit_logs')->where('action', 'team.invitation.revoked')->where('subject_id', $invitation->id)->value('actor_id'));
            $this->assertSame($admin->id, DB::table('audit_logs')->where('action', 'brand.archived')->where('subject_id', $target->id)->value('actor_id'));
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
    }

    public function test_brand_archive_first_blocks_revocation_and_leaves_only_archive_audit(): void
    {
        [$owner, $org, $member, $selected, $target, $invitation, $context] = $this->fixture();
        [$admin, $adminMember] = $this->admin($org, $owner);
        $archive = $this->request($owner, $org, $member, $selected, 'archive', $target);
        $revoke = $this->request($admin, $org, $adminMember, $selected, 'revoke', $target);
        $name = 'e106-revoke-'.Str::lower(Str::random(8));
        $worker = $this->worker('revoke', $name, $admin, $org, $adminMember, $selected, $target, $invitation, $revoke);
        Auth::setUser($owner);
        DB::beginTransaction();
        try {
            app(TenantContextRunner::class)->run($context, fn () => app(OrganizationController::class)->archiveBrand($archive, $target->id));
            $this->waitBlocked($worker, $name);
            DB::commit();
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('denied:404', $worker->getOutput());
            $this->assertNull(DB::table('invitations')->where('id', $invitation->id)->value('revoked_at'));
            $this->assertSame('archived', DB::table('brands')->where('id', $target->id)->value('status'));
            $this->assertAudit($invitation, $target, 0, 1);
            $this->assertSame($owner->id, DB::table('audit_logs')->where('action', 'brand.archived')->where('subject_id', $target->id)->value('actor_id'));
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
    }

    public function test_viewer_and_foreign_invitation_cannot_be_revoked(): void
    {
        [$owner, $org, $member, $selected, $target, $invitation, $context] = $this->fixture();
        $viewer = User::create(['name' => 'Synthetic viewer', 'email' => Str::lower(Str::random(16)).'@example.test', 'password' => 'test-only-password']);
        $viewer->forceFill(['email_verified_at' => now()])->save();
        $viewer->refresh();
        $viewerMember = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $owner->id), fn () => Membership::create(['user_id' => $viewer->id, 'role' => 'viewer', 'brand_ids' => [$selected->id]]));
        $viewerRequest = $this->request($viewer, $org, $viewerMember, $selected, 'revoke', $target);
        $viewerContext = new TenantContext($org->id, $selected->id, $viewer->id, $viewerMember->id);
        try {
            app(TenantContextRunner::class)->run($viewerContext, fn () => app(InvitationRevocation::class)->revoke($viewerRequest, $invitation->id));
            $this->fail('Viewer could revoke an invitation.');
        } catch (AuthorizationException $exception) {
            $this->assertFalse($exception->hasStatus());
        }
        $foreignOwner = User::create(['name' => 'Foreign owner', 'email' => Str::lower(Str::random(16)).'@example.test', 'password' => 'test-only-password']);
        $foreignOrg = Organization::create(['name' => 'Foreign organization', 'owner_user_id' => $foreignOwner->id]);
        $foreignInvitation = app(TenantContextRunner::class)->run(new TenantContext($foreignOrg->id, actorId: $foreignOwner->id), function () use ($foreignOwner): Invitation {
            $brand = Brand::create(['name' => 'Foreign brand', 'theme' => 'neutral']);

            return Invitation::create(['email' => 'foreign-invite@example.test', 'role' => 'viewer', 'brand_ids' => [$brand->id], 'token_hash' => hash('sha256', Str::random(64)), 'invited_by' => $foreignOwner->id, 'expires_at' => now()->addDay()]);
        });
        $ownerRequest = $this->request($owner, $org, $member, $selected, 'revoke', $target);
        try {
            app(TenantContextRunner::class)->run($context, fn () => app(InvitationRevocation::class)->revoke($ownerRequest, $foreignInvitation->id));
            $this->fail('Foreign invitation was revoked.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(Invitation::class, $exception->getModel());
        }
        $this->assertNull(DB::table('invitations')->where('id', $invitation->id)->value('revoked_at'));
        $this->assertNull(DB::table('invitations')->where('id', $foreignInvitation->id)->value('revoked_at'));
        $this->assertAudit($invitation, $target, 0, 0);
        $this->assertSame(0, DB::table('audit_logs')->where('subject_id', $foreignInvitation->id)->count());
    }
}
