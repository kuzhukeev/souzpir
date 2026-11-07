<?php

namespace App\Seller\Notifications;

use App\Seller\Conversations\Models\Message;
use Illuminate\Support\Facades\DB;

final class QueueInboundNotification
{
    public function handle(Message $message): void
    {
        if ($message->role !== 'client') {
            return;
        }
        $channel = $message->channel_account_id ? DB::table('channel_accounts')->where('id', $message->channel_account_id)->value('type') : null;
        NotifyConversationEvent::dispatch($message->organization_id, $message->brand_id, $message->conversation_id,
            'seller.conversation.inbound', ['channel' => $channel ?? 'unknown'])->afterCommit();
    }
}
