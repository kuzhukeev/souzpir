<?php

namespace App\Seller\Channels\Telegram;

use App\Seller\Dialogs\ManagerMessageTransport;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Sends a persisted manager reply to the private Telegram chat of its own conversation. */
final class TelegramManagerMessageTransport implements ManagerMessageTransport
{
    public function send(string $organizationId, string $brandId, string $conversationId, string $messageId): void
    {
        if (! config('features.E5-01.defaults.manager_telegram_delivery', false)) {
            throw new LogicException('Telegram manager delivery is disabled.');
        }
        $message = DB::table('messages')->where(['organization_id' => $organizationId, 'brand_id' => $brandId,
            'conversation_id' => $conversationId, 'id' => $messageId, 'role' => 'manager'])->first(['text']);
        $conversation = DB::table('conversations')->where(['organization_id' => $organizationId, 'brand_id' => $brandId,
            'id' => $conversationId])->first(['channel_account_id', 'state']);
        $state = $conversation ? json_decode($conversation->state, true) : null;
        $chatId = is_array($state) ? ($state['telegram_chat_id'] ?? null) : null;
        if (! $message || ! $conversation || ! is_string($conversation->channel_account_id) || ! is_string($chatId)
            || ! app(TelegramSender::class)->send(new TenantContext($organizationId, $brandId, source: 'job'), $conversation->channel_account_id, $chatId, $message->text)) {
            throw new LogicException('Telegram manager delivery unavailable.');
        }
    }
}
