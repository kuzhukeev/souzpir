<?php

namespace App\Shell\Team;

use App\Models\Membership;
use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Team\Models\Invitation;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TeamActions
{
    public function __construct(private TeamAccess $access, private AuditWriter $audit, private TeamAssignments $assignments) {}

    public function update(Request $request, Membership $subject, array $data): void
    {
        $this->memberMutation($request, $subject, function (Membership $member, Membership $actor) use ($data): void {
            $grant = $this->access->grants($actor, $data['role'], $data['brand_ids']);
            $before = $this->memberState($member);
            $member->fill(['role' => $grant['role'], 'brand_ids' => $grant['brand_ids'], 'settings' => [...$member->settings, 'sales_manager' => $grant['sales_manager']]]);
            if ($member->isDirty()) {
                $this->assignments->assertCanPersist($member);
                $member->save();
                $this->record('team.member.updated', 'membership', $member->id, $before, $this->memberState($member));
            }
        });
    }

    public function disable(Request $request, Membership $subject): void
    {
        $this->memberMutation($request, $subject, function (Membership $member, Membership $actor, User $user): void {
            $member->status = 'disabled';
            $this->assignments->assertCanPersist($member);
            $member->save();
            $user->forceFill(['auth_version' => $user->auth_version + 1, 'remember_token' => Str::random(60)])->save();
            // Version and remember-token rotation invalidate existing sessions on their next check.
            // Do not lock target session rows after the user: withinActive locks session before user.
            $this->record('team.member.disabled', 'membership', $member->id, ['status' => 'active'], ['status' => 'disabled']);
        });
    }

    private function memberMutation(Request $request, Membership $subject, callable $change): void
    {
        app(AuthSessionRegistry::class)->withinActive($request, function () use ($subject, $change): void {
            try {
                // Another actor may hold its own user lock. Never wait here while holding ours.
                $user = User::whereKey($subject->user_id)->lock('for no key update nowait')->firstOrFail();
            } catch (QueryException $error) {
                if (($error->errorInfo[0] ?? null) === '55P03') {
                    abort(409, __('Shell/Team/messages.account_busy'));
                }
                throw $error;
            }
            $actor = $this->access->lock();
            $member = $this->access->member($subject->id, true, $actor);
            Gate::authorize('update', $member);
            abort_unless($member->status === 'active' && $this->access->controls($member, $actor), 403);
            $change($member, $actor, $user);
        });
    }

    /** Raw link is ephemeral; never place it in an Eloquent model, audit or outbox. */
    public function invite(Request $request, array $data): array
    {
        return app(AuthSessionRegistry::class)->withinActive($request, function () use ($data): array {
            $actor = $this->access->lock();
            $grant = $this->access->grants($actor, $data['role'], $data['brand_ids']);
            $email = mb_strtolower(trim($data['email']));
            $this->assertNewRecipient($email, $actor->organization_id);
            if (Invitation::query()->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->exists()) {
                throw ValidationException::withMessages(['email' => __('Shell/Team/messages.already_invited')]);
            }
            $issued = $this->issue($email, $grant, $actor);
            $this->record('team.invitation.created', 'invitation', $issued['invitation']->id, null, $this->invitationState($issued['invitation']));

            return $issued;
        });
    }

    public function resend(Request $request, Invitation $subject): array
    {
        return app(AuthSessionRegistry::class)->withinActive($request, function () use ($subject): array {
            $actor = $this->access->lock();
            $old = $this->access->invitation($subject->id, true, $actor);
            abort_unless($this->access->controls($old, $actor), 403);
            abort_if($old->accepted_at || $old->revoked_at, 409, __('Shell/Team/messages.invitation_consumed'));
            $this->assertNewRecipient($old->email, $actor->organization_id);
            if (Invitation::query()->where('email', $old->email)->whereKeyNot($old->id)
                ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->exists()) {
                throw ValidationException::withMessages(['email' => __('Shell/Team/messages.already_invited')]);
            }
            $grant = $this->access->grants($actor, TeamRoles::name($old), $old->brand_ids);
            $old->revoked_at = now();
            $old->save();
            $issued = $this->issue($old->email, $grant, $actor);
            $this->record('team.invitation.resent', 'invitation', $old->id, null, ['new_invitation_id' => $issued['invitation']->id, ...$this->invitationState($issued['invitation'])]);

            return $issued;
        });
    }

    public function revoke(Request $request, Invitation $subject): void
    {
        app(AuthSessionRegistry::class)->withinActive($request, function () use ($subject): void {
            $actor = $this->access->lock();
            $invitation = $this->access->invitation($subject->id, true, $actor);
            abort_unless($this->access->controls($invitation, $actor), 403);
            abort_if($invitation->accepted_at, 409, __('Shell/Team/messages.invitation_consumed'));
            if ($invitation->revoked_at === null) {
                $invitation->revoked_at = now();
                $invitation->save();
                $this->record('team.invitation.revoked', 'invitation', $invitation->id, null, ['revoked_at' => $invitation->revoked_at->toIso8601String()]);
            }
        });
    }

    private function issue(string $email, array $grant, Membership $actor): array
    {
        $token = Str::random(64);
        $invitation = Invitation::create(['email' => $email, 'role' => $grant['role'], 'brand_ids' => $grant['brand_ids'],
            'settings' => ['sales_manager' => $grant['sales_manager']], 'token_hash' => hash('sha256', $token),
            'invited_by' => $actor->user_id, 'expires_at' => now()->addDays(7)]);

        return ['invitation' => $invitation, 'url' => url('/invitations/open').'#'.$token];
    }

    private function assertNewRecipient(string $email, string $organizationId): void
    {
        if (DB::table('memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.organization_id', $organizationId)->whereRaw('lower(u.email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => __('Shell/Team/messages.already_member')]);
        }
    }

    private function memberState(Membership $member): array
    {
        return ['role' => TeamRoles::name($member), 'brand_ids' => $member->brand_ids];
    }

    private function invitationState(Invitation $invitation): array
    {
        return ['role' => TeamRoles::name($invitation), 'brand_ids' => $invitation->brand_ids, 'expires_at' => $invitation->expires_at->toIso8601String()];
    }

    private function record(string $action, string $type, string $id, ?array $before, ?array $after): void
    {
        $this->audit->record(app(TenantContext::class), new AuditEntry($action, $type, $id, $before, $after));
    }
}
