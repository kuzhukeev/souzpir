<?php

namespace App\Tenancy\Invitations;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\InvitationExchangeGrants;
use App\Shell\Team\Models\Invitation;
use App\Tenancy\BrandGrants;
use App\Tenancy\Cabinet;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InvitationAcceptance implements AcceptsInvitations
{
    public function inspect(User $user, #[\SensitiveParameter] string $token): InvitationDetails
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{64}$/D', $token), 404);

        return $this->inspectIdentifier($user, 'token_hash', hash('sha256', $token));
    }

    /** Session grant can only originate from the bearer exchange. */
    public function inspectSession(User $user, #[\SensitiveParameter] Request $request): InvitationDetails
    {
        return $this->inspectIdentifier($user, 'id', $this->sessionInvitationId($request));
    }

    private function inspectIdentifier(User $user, string $column, string $identifier): InvitationDetails
    {
        $invitation = $this->find($user, $column, $identifier);

        return new InvitationDetails($invitation->id, $invitation->organization_id, $invitation->email, $invitation->role, $invitation->brand_ids, $invitation->expires_at->toISOString());
    }

    public function accept(User $user, #[\SensitiveParameter] string $token): Membership
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{64}$/D', $token), 404);

        return $this->acceptIdentifier($user, 'token_hash', hash('sha256', $token));
    }

    /** Session grant can only originate from the bearer exchange. */
    public function acceptSession(User $user, #[\SensitiveParameter] Request $request): Membership
    {
        return $this->acceptIdentifier($user, 'id', '', $request);
    }

    private function sessionInvitationId(#[\SensitiveParameter] Request $request, bool $lock = false): string
    {
        abort_unless($request->hasSession(), 404);
        $id = app(InvitationExchangeGrants::class)->invitationId($request, $lock);
        abort_unless(is_string($id) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/iD', $id), 404);

        return $id;
    }

    private function acceptIdentifier(User $user, string $column, string $identifier, ?Request $request = null): Membership
    {
        abort_unless($user->exists && $user->hasVerifiedEmail(), 403);

        return DB::transaction(function () use ($user, $column, $identifier, $request): Membership {
            $organizationId = app(Cabinet::class)->organizationId();
            // All session-sensitive flows lock user before organization, invitation and membership.
            $actor = User::query()->whereKey($user->id)->lockForUpdate()->first();
            abort_unless($actor && $actor->hasVerifiedEmail(), 403);
            if ($request) {
                $identifier = $this->sessionInvitationId($request, true);
            }
            $organization = Organization::query()->whereKey($organizationId)->where('status', 'active')->lockForUpdate()->first();
            abort_unless($organization, 404);
            $invitation = $this->find($actor, $column, $identifier, true);

            return app(TenantContextRunner::class)->run(new TenantContext($organizationId, actorId: $actor->id), function () use ($actor, $invitation): Membership {
                $membership = Membership::query()->where('user_id', $actor->id)->lockForUpdate()->first();
                if ($membership === null) {
                    $membership = Membership::create([
                        'user_id' => $actor->id,
                        'role' => $invitation->role,
                        'status' => 'active',
                        'brand_ids' => $invitation->brand_ids,
                        'settings' => $invitation->settings,
                        'invited_by' => $invitation->invited_by,
                    ]);
                }
                // Existing active/disabled membership is not upgraded, downgraded or reactivated.
                $invitation->accepted_at = now();
                $invitation->accepted_by = $actor->id;
                $invitation->save();

                return $membership;
            });
        });
    }

    private function find(User $user, string $column, string $identifier, bool $lock = false): Invitation
    {
        abort_unless($user->exists && (($column === 'token_hash' && preg_match('/^[a-f0-9]{64}$/D', $identifier))
            || ($column === 'id' && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/iD', $identifier))), 404);
        $organizationId = app(Cabinet::class)->organizationId();
        abort_unless(Organization::query()->whereKey($organizationId)->where('status', 'active')->exists(), 404);
        $query = Invitation::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where($column, $identifier)
            ->where('email', mb_strtolower(trim($user->email)))
            ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());
        if ($column === 'token_hash') {
            $query->whereNull('exchanged_at');
        }
        $invitation = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($invitation, 404);
        try {
            BrandGrants::validate($organizationId, $invitation->brand_ids);
        } catch (ValidationException) {
            abort(404);
        }

        return $invitation;
    }
}
