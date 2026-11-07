<?php

namespace App\Seller\Conversations\Models;

final class Conversation extends SellerModel
{
    use ValidatesAssignee;

    protected function casts(): array
    {
        return ['state' => 'array', 'do_not_disturb' => 'boolean', 'version' => 'integer', 'followups_sent' => 'integer', 'next_followup_at' => 'datetime', 'last_client_message_at' => 'datetime', 'last_bot_message_at' => 'datetime', 'handed_off_at' => 'datetime', 'taken_at' => 'datetime'];
    }
}
