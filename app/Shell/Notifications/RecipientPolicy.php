<?php

namespace App\Shell\Notifications;

use App\Models\Membership;
use Illuminate\Support\Facades\DB;

final class RecipientPolicy
{
    public function permits(Membership $member, string $brandId, string $eventKey): bool
    {
        if ($eventKey === 'shell.plan_request.created') {
            return $member->status === 'active' && $member->role === 'owner' && $member->permitsBrand($brandId)
                && DB::table('organizations')->where('id', $member->organization_id)->where('status', 'active')
                    ->where('owner_user_id', $member->user_id)->exists()
                && DB::table('brands')->where('id', $brandId)->where('organization_id', $member->organization_id)
                    ->where('status', 'active')->exists();
        }

        return $member->status === 'active'
            && $member->permitsBrand($brandId)
            && (in_array($member->role, ['owner', 'admin'], true)
                || ($member->role === 'member' && in_array($eventKey, ['seller.conversation.handed_off', 'seller.contact.reminder',
                    'seller.conversation.inbound', 'seller.draft.ready'], true)));
    }
}
