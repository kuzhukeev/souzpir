<?php

namespace App\Shell\Support;

use App\Shell\Support\Models\SupportAccessGrant;
use App\Shell\Support\Models\Ticket;

final class SupportConsent
{
    public function current(Ticket $ticket): ?SupportAccessGrant
    {
        return SupportAccessGrant::query()->where('organization_id', $ticket->organization_id)->where('ticket_id', $ticket->id)->where('granted_by', $ticket->user_id)->first();
    }

    public function status(SupportAccessGrant $grant): string
    {
        if ($grant->revoked_at !== null) {
            return 'revoked';
        }

        return now()->greaterThanOrEqualTo($grant->expires_at) ? 'expired' : 'active';
    }

    /** Consent alone is never a staff identity, membership or authorization to enter the cabinet. */
    public function hasCurrentConsent(Ticket $ticket): bool
    {
        $grant = $this->current($ticket);

        return $grant !== null && $this->status($grant) === 'active';
    }
}
