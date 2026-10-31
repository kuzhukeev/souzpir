<?php

namespace App\Shell\Support;

use App\Models\User;
use App\Shell\Support\Models\Ticket;
use App\Tenancy\TenantContext;

final class TicketPolicy
{
    public function create(User $user): bool
    {
        $member = app(SupportAccess::class)->actor();

        // Current shared tenant middleware does not yet allow viewer self-service POSTs.
        return $member->user_id === $user->id && $member->canWrite();
    }

    public function view(User $user, Ticket $ticket): bool
    {
        return $ticket->type === 'support' && $ticket->user_id === $user->id
            && $ticket->organization_id === app(TenantContext::class)->organizationId
            && app(SupportAccess::class)->actor()->user_id === $user->id;
    }

    public function revoke(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket) && $this->create($user);
    }
}
