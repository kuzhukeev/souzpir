<?php

namespace App\Tenancy\Invitations;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Team\Models\Invitation;
use App\Tenancy\BrandGrants;
use App\Tenancy\Cabinet;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InvitationAcceptance implements AcceptsInvitations
{
    public function inspect(User $user, #[\SensitiveParameter] string $token): InvitationDetails
    {
        $invitation = $this->find($user, $token);

        return new InvitationDetails($invitation->id, $invitation->organization_id, $invitation->email, $invitation->role, $invitation->brand_ids, $invitation->expires_at->toISOString());
    }

    public function accept(User $user, #[\SensitiveParameter] string $token): Membership
    {
        abort_unless($user->exists && $user->hasVerifiedEmail(), 403);

        return DB::transaction(function () use ($user, $token): Membership {
            $organizationId = app(Cabinet::class)->organizationId();
            // All session-sensitive flows lock user before organization, invitation and membership.
            $actor = User::query()->whereKey($user->id)->lockForUpdate()->first();
            abort_unless($actor && $actor->hasVerifiedEmail(), 403);
            $organization = Organization::query()->whereKey($organizationId)->where('status', 'active')->lockForUpdate()->first();
            abort_unless($organization, 404);
            $invitation = $this->find($actor, $token, true);

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

    private function find(User $user, #[\SensitiveParameter] string $token, bool $lock = false): Invitation
    {
        abort_unless($user->exists && preg_match('/^[A-Za-z0-9]{64}$/D', $token), 404);
        $organizationId = app(Cabinet::class)->organizationId();
        abort_unless(Organization::query()->whereKey($organizationId)->where('status', 'active')->exists(), 404);
        $query = Invitation::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('token_hash', hash('sha256', $token))
            ->where('email', mb_strtolower(trim($user->email)))
            ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());
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
