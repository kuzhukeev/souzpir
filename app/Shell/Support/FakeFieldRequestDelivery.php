<?php

namespace App\Shell\Support;

use App\Shell\Support\Models\Ticket;

final class FakeFieldRequestDelivery
{
    public function send(Ticket $ticket): void
    {
        // The ticket remains in support, but no email is sent in fake mode.
        Ticket::query()->whereKey($ticket->id)->where('user_id', $ticket->user_id)->where('delivery_status', 'pending')
            ->update(['delivery_status' => 'disabled', 'updated_at' => now()]);
    }
}
