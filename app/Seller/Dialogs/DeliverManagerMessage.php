<?php

namespace App\Seller\Dialogs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class DeliverManagerMessage implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $organizationId, public string $brandId, public string $conversationId, public string $messageId) {}

    public function handle(): void
    {
        // Fake transport: the MVP records delivery without contacting an external channel.
        DB::table('messages')->where('organization_id', $this->organizationId)->where('brand_id', $this->brandId)
            ->where('conversation_id', $this->conversationId)->where('id', $this->messageId)->where('role', 'manager')
            ->update(['delivered_at' => now(), 'updated_at' => now()]);
    }
}
