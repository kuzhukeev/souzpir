<?php

namespace Tests\Feature\Shell\Team;

use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Team\Models\Invitation;
use App\Tenancy\Invitations\AcceptsInvitations;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

final class TeamMutationGuardsTest extends TeamTestCase
{
    public function test_restricted_admin_cannot_resend_or_revoke_hidden_or_partially_visible_invitation(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$admin] = $this->member($org, 'admin', [$brand->id]);
        $this->signIn($owner, $brand);
        $hidden = $this->invitation('hidden@example.test', 'viewer', [$other->id]);
        $shared = $this->invitation('shared@example.test', 'viewer', [$brand->id, $other->id]);
        $visible = $this->invitation('visible@example.test', 'viewer', [$brand->id]);
        $this->signIn($admin, $brand);
        foreach ([[$hidden, 404], [$shared, 403]] as [$invitation, $expected]) {
            foreach (['resend', 'revoke'] as $action) {
                self::assertSame($expected, $this->postJson('/team/invitations/'.$invitation['id'].'/'.$action)->status());
            }
            self::assertNull(Invitation::withoutGlobalScopes()->findOrFail($invitation['id'])->revoked_at);
        }
        self::assertSame(3, Invitation::withoutGlobalScopes()->count());
        self::assertSame(0, DB::table('audit_logs')->whereIn('action', ['team.invitation.resent', 'team.invitation.revoked'])->count());
        self::assertSame(200, $this->postJson('/team/invitations/'.$visible['id'].'/revoke')->status());
        Mail::assertNothingSent();
    }

    public function test_editor_manager_and_viewer_cannot_call_any_team_mutation(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        [, $target] = $this->member($org, 'viewer', [$brand->id]);
        $this->signIn($owner, $brand);
        $invitation = $this->invitation('untouched@example.test', 'viewer', [$brand->id]);
        foreach ([['member', false], ['member', true], ['viewer', false]] as [$role, $manager]) {
            [$actor] = $this->member($org, $role, [$brand->id], $manager);
            $this->signIn($actor, $brand);
            self::assertSame(403, $this->patchJson('/team/'.$target->id, ['role' => 'admin', 'brand_ids' => []])->status());
            self::assertSame(403, $this->postJson('/team/'.$target->id.'/disable')->status());
            self::assertSame(403, $this->postJson('/team/invitations/'.$invitation['id'].'/resend')->status());
            self::assertSame(403, $this->postJson('/team/invitations/'.$invitation['id'].'/revoke')->status());
        }
        self::assertSame('active', $target->fresh()->status);
        self::assertSame('viewer', $target->fresh()->role);
        self::assertNull(Invitation::withoutGlobalScopes()->findOrFail($invitation['id'])->revoked_at);
        self::assertSame(1, Invitation::withoutGlobalScopes()->count());
        self::assertSame(0, DB::table('audit_logs')->whereIn('action', ['team.member.updated', 'team.member.disabled', 'team.invitation.resent', 'team.invitation.revoked'])->count());
        Mail::assertNothingSent();
    }

    public function test_failed_audit_rolls_back_resend_and_disable_with_no_delivery_or_session_change(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $invitee = $this->user();
        [$target, $member] = $this->member($org, 'viewer', [$brand->id]);
        $target->forceFill(['remember_token' => Str::random(60)])->save();
        $target->refresh();
        $version = $target->auth_version;
        $remember = $target->getRememberToken();
        $this->signIn($owner, $brand);
        $issued = $this->invitation($invitee->email, 'viewer', [$brand->id]);
        config(['features.E1-06.defaults.mail_enabled' => true, 'features.E1-06.defaults.smtp_enabled' => true]);
        app()->instance(AuditWriter::class, new class implements AuditWriter
        {
            public function record(TenantContext $context, AuditEntry $entry): void
            {
                throw new RuntimeException('Audit rollback fixture.');
            }
        });
        self::assertSame(500, $this->postJson('/team/invitations/'.$issued['id'].'/resend')->status());
        self::assertNull(Invitation::withoutGlobalScopes()->findOrFail($issued['id'])->revoked_at);
        self::assertSame(1, Invitation::withoutGlobalScopes()->count());
        self::assertSame($issued['id'], app(AcceptsInvitations::class)->inspect($invitee, $this->token($issued))->id);
        self::assertSame(500, $this->postJson('/team/'.$member->id.'/disable')->status());
        self::assertSame('active', $member->fresh()->status);
        self::assertSame($version, $target->fresh()->auth_version);
        self::assertTrue(hash_equals($remember, $target->fresh()->getRememberToken()));
        self::assertSame(0, DB::table('audit_logs')->whereIn('action', ['team.invitation.resent', 'team.member.disabled'])->count());
        Mail::assertNothingSent();
    }
}
