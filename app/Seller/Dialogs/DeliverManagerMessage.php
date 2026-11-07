<?php

namespace App\Seller\Dialogs;

use App\Seller\Channels\Telegram\TelegramManagerMessageTransport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class DeliverManagerMessage implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $organizationId, public string $brandId, public string $conversationId, public string $messageId) {}

    public function handle(): void
    {
        $message = DB::table('messages')->where('organization_id', $this->organizationId)->where('brand_id', $this->brandId)
            ->where('conversation_id', $this->conversationId)->where('id', $this->messageId)->where('role', 'manager')->first();
        if (! $message) {
            return;
        }
        $channel = DB::table('conversations as c')->join('channel_accounts as a', function ($join): void {
            $join->on('a.id', '=', 'c.channel_account_id')->on('a.organization_id', '=', 'c.organization_id')->on('a.brand_id', '=', 'c.brand_id');
        })->where('c.organization_id', $this->organizationId)->where('c.brand_id', $this->brandId)
            ->where('c.id', $this->conversationId)->value('a.type');
        $transport = config('features.E5-01.defaults.manager_telegram_delivery', false) && $channel === 'telegram'
            ? app(TelegramManagerMessageTransport::class)
            : (app()->bound(ManagerMessageTransport::class) ? app(ManagerMessageTransport::class) : app(FakeManagerMessageTransport::class));
        $transport->send($this->organizationId, $this->brandId, $this->conversationId, $this->messageId);
        DB::table('messages')->where('organization_id', $this->organizationId)->where('brand_id', $this->brandId)
            ->where('conversation_id', $this->conversationId)->where('id', $this->messageId)->where('role', 'manager')
            ->update(['delivered_at' => now(), 'updated_at' => now()]);
    }
}
