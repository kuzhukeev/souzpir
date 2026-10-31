<?php

namespace Tests\Feature\Shell\Team;

use App\Models\Organization;
use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Shell\Team\InvitationRevocation;
use App\Shell\Team\Models\Invitation;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Tenancy\TenancyTestCase;

final class InvitationRevocationTest extends TenancyTestCase
{
    private function invite(Organization $organization, User $actor, array $brands, string $role = 'member'): Invitation
    {
        return $this->within($organization, null, fn () => Invitation::create([
            'email' => Str::lower(Str::random(12)).'@example.test', 'role' => $role, 'brand_ids' => $brands,
            'token_hash' => hash('sha256', Str::random(64)), 'invited_by' => $actor->id, 'expires_at' => now()->addDay(),
        ]));
    }

    public function test_owner_revokes_pending_invitation_and_writes_single_sanitized_audit(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $invitation = $this->invite($org, $owner, [$brand->id]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/team/invitations/'.$invitation->id.'/revoke')->assertRedirect('/team');
        $this->assertNotNull(DB::table('invitations')->where('id', $invitation->id)->value('revoked_at'));
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $org->id, 'actor_id' => $owner->id, 'action' => 'team.invitation.revoked', 'subject_type' => 'invitation', 'subject_id' => $invitation->id]);
        $audit = DB::table('audit_logs')->where('action', 'team.invitation.revoked')->where('subject_id', $invitation->id)->first();
        $this->assertStringNotContainsString($invitation->email, json_encode($audit, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($invitation->token_hash, json_encode($audit, JSON_THROW_ON_ERROR));
        $this->post('/team/invitations/'.$invitation->id.'/revoke')->assertNotFound();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'team.invitation.revoked')->where('subject_id', $invitation->id)->count());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_restricted_admin_can_revoke_only_grants_fully_within_access(): void
    {
        [$owner, $org, $first, $second] = $this->cabinet();
        [$admin] = $this->member($org, 'admin', [$first->id]);
        $allowed = $this->invite($org, $owner, [$first->id]);
        $mixed = $this->invite($org, $owner, [$first->id, $second->id]);
        $wildcard = $this->invite($org, $owner, [], 'admin');
        $brandless = $this->invite($org, $owner, []);
        $invalid = $this->invite($org, $owner, [$first->id]);
        DB::table('invitations')->where('id', $invalid->id)->update(['brand_ids' => json_encode([$first->id, $first->id], JSON_THROW_ON_ERROR)]);
        $this->actingAs($admin)->withSession(['brand_id' => $first->id]);
        $rows = collect($this->get('/team')->assertOk()->inertiaProps('invitations.data'))->keyBy('id');
        $this->assertTrue($rows[$allowed->id]['can_revoke']);
        $this->assertFalse($rows[$mixed->id]['can_revoke']);
        $this->assertFalse($rows[$wildcard->id]['can_revoke']);
        $this->assertFalse($rows[$invalid->id]['can_revoke']);
        foreach ([$mixed, $wildcard, $brandless, $invalid] as $denied) {
            $this->post('/team/invitations/'.$denied->id.'/revoke')->assertNotFound();
            $this->assertNull(DB::table('invitations')->where('id', $denied->id)->value('revoked_at'));
        }
        $this->post('/team/invitations/'.$allowed->id.'/revoke')->assertRedirect('/team');
        $this->assertNotNull(DB::table('invitations')->where('id', $allowed->id)->value('revoked_at'));
    }

    public function test_forbidden_foreign_accepted_expired_and_disabled_actor_never_mutate(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        [$editor] = $this->member($org, 'member', [$brand->id]);
        [$admin, $adminMembership] = $this->member($org, 'admin', [$brand->id]);
        $pending = $this->invite($org, $owner, [$brand->id]);
        $accepted = $this->invite($org, $owner, [$brand->id]);
        $expired = $this->invite($org, $owner, [$brand->id]);
        DB::table('invitations')->where('id', $accepted->id)->update(['accepted_at' => now(), 'accepted_by' => $owner->id]);
        DB::table('invitations')->where('id', $expired->id)->update(['expires_at' => now()->subSecond()]);
        [$foreignOwner, $foreignOrg, $foreignBrand] = $this->cabinet(false);
        $foreign = $this->invite($foreignOrg, $foreignOwner, [$foreignBrand->id]);
        $this->post('/team/invitations/'.$pending->id.'/revoke')->assertRedirect('/login');
        foreach ([$viewer, $editor] as $actor) {
            $this->actingAs($actor)->withSession(['brand_id' => $brand->id])->post('/team/invitations/'.$pending->id.'/revoke')->assertForbidden();
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        foreach ([$accepted, $expired, $foreign] as $denied) {
            $this->post('/team/invitations/'.$denied->id.'/revoke')->assertNotFound();
            $this->assertNull(DB::table('invitations')->where('id', $denied->id)->value('revoked_at'));
        }
        $this->actingAs($admin)->withSession(['brand_id' => $brand->id]);
        DB::table('memberships')->where('id', $adminMembership->id)->update(['status' => 'disabled']);
        $this->post('/team/invitations/'.$pending->id.'/revoke')->assertForbidden();
        $this->assertNull(DB::table('invitations')->where('id', $pending->id)->value('revoked_at'));
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'team.invitation.revoked')->count());
    }

    public function test_audit_failure_rolls_back_revocation(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $invitation = $this->invite($org, $owner, [$brand->id]);
        $this->app->instance(AuditWriter::class, new class implements AuditWriter
        {
            public function record(TenantContext $context, AuditEntry $entry): void
            {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/team/invitations/'.$invitation->id.'/revoke')->assertServerError();
        $this->assertNull(DB::table('invitations')->where('id', $invitation->id)->value('revoked_at'));
    }

    public function test_stale_selection_and_revision_cannot_revoke_or_audit(): void
    {
        [$owner, $org, $first, $second, $membership] = $this->cabinet();
        $invitation = $this->invite($org, $owner, [$first->id]);
        $this->actingAs($owner)->withSession(['brand_id' => $first->id])->get('/team')->assertOk();
        $request = app('request');
        $revision = $request->attributes->get('tenant.brand_revision');
        $this->assertIsString($revision);
        $selection = app(SessionBrandSelection::class);
        app(AuthSessionRegistry::class)->withinActive($request, fn () => $selection->select($request, $org->id, $second->id, $revision));
        $context = new TenantContext($org->id, $first->id, $owner->id, $membership->id);
        foreach ([false, true] as $backToSameBrand) {
            if ($backToSameBrand) {
                app(AuthSessionRegistry::class)->withinActive($request, fn () => $selection->select($request, $org->id, $first->id, (string) ((int) $revision + 1)));
            }
            try {
                app(TenantContextRunner::class)->run($context, fn () => app(InvitationRevocation::class)->revoke($request, $invitation->id));
                $this->fail('Stale brand context was allowed to revoke.');
            } catch (HttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
            $this->assertNull(DB::table('invitations')->where('id', $invitation->id)->value('revoked_at'));
            $this->assertSame(0, DB::table('audit_logs')->where('action', 'team.invitation.revoked')->count());
        }
        $request->attributes->remove('tenant.brand_revision');
        try {
            app(TenantContextRunner::class)->run($context, fn () => app(InvitationRevocation::class)->revoke($request, $invitation->id));
            $this->fail('Missing brand revision was allowed to revoke.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertNull(DB::table('invitations')->where('id', $invitation->id)->value('revoked_at'));
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'team.invitation.revoked')->count());
    }
}
