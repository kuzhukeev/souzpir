<?php

namespace App\Seller\Live;

use App\Seller\Conversations\Models\Message;

final class QueueLiveDraft
{
    public function handle(Message $message): void
    {
        if ($message->role !== 'client' || ! config('features.LV-01.defaults.enabled', false)) {
            return;
        }
        LiveDraftJob::dispatch($message->organization_id, $message->brand_id, $message->conversation_id, $message->id)->afterCommit();
    }
}
