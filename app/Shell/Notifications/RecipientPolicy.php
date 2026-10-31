<?php

namespace App\Shell\Notifications;

use App\Models\Membership;

final class RecipientPolicy
{
    public function permits(Membership $member, string $brandId, string $eventKey): bool
    {
        return $member->status === 'active'
            && $member->permitsBrand($brandId)
            && (in_array($member->role, ['owner', 'admin'], true)
                || ($member->role === 'member' && $eventKey === 'seller.conversation.handed_off'));
    }
}
