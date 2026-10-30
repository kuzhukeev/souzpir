<?php

namespace App\Tenancy\Invitations;

use App\Models\Membership;
use App\Models\User;

interface AcceptsInvitations
{
    /** Unknown, expired, revoked, consumed, foreign-organization or mismatched-email tokens all return 404. */
    public function inspect(User $user, #[\SensitiveParameter] string $token): InvitationDetails;

    /** Requires verified email. Atomic, one use; existing membership is retained without changing grants. */
    public function accept(User $user, #[\SensitiveParameter] string $token): Membership;
}
