<?php

namespace App\Shell\Permissions\Policies;

use App\Models\Membership;
use App\Models\User;
use App\Shell\Permissions\PermissionEvaluator;

final class MembershipPolicy
{
    public function viewAny(User $user): bool
    {
        return app(PermissionEvaluator::class)->allows($user, 'team.read');
    }

    public function create(User $user): bool
    {
        return app(PermissionEvaluator::class)->allows($user, 'team.write');
    }

    public function view(User $user, Membership $membership): bool
    {
        return app(PermissionEvaluator::class)->allows($user, 'team.read', $membership);
    }

    public function update(User $user, Membership $membership): bool
    {
        // Ownership transfer is a separate, atomic workflow; never edit owner/self here.
        return $membership->role !== 'owner' && $membership->user_id !== $user->id &&
            app(PermissionEvaluator::class)->allows($user, 'team.write', $membership);
    }
}
