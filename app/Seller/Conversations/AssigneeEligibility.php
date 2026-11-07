<?php

namespace App\Seller\Conversations;

use App\Models\Membership;
use App\Shell\Permissions\PermissionEvaluator;

final class AssigneeEligibility
{
    public static function canOperate(Membership $membership): bool
    {
        AssignmentPolicy::assertConfiguration();
        $allowed = config('permissions.abilities', [])['seller.dialogs.write'] ?? null;
        $role = app(PermissionEvaluator::class)->role($membership);

        return is_array($allowed) && $role !== null && in_array($role, $allowed, true);
    }
}
