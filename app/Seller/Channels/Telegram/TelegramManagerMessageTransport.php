<?php

namespace App\Seller\Channels\Telegram;

use App\Seller\Channels\Core\ChannelAccount;
use App\Seller\Dialogs\ManagerMessageTransport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;

/** Sends a persisted manager reply to the private Telegram chat of its own conversation, with real delivery status. */
final class TelegramManagerMessageTransport implements ManagerMessageTransport
{
    public function send(string $organizationId, string $brandId, string $conversationId, string $messageId): void
    {
        if (! config('features.E5-01.defaults.manager_telegram_delivery', false)) {
            throw new LogicException('Telegram manager delivery is disabled.');
        }
        $message = DB::table('messages')->where(['organization_id' => $organizationId, 'brand_id' => $brandId,
            'conversation_id' => $conversationId, 'id' => $messageId, 'role' => 'manager'])->first(['text', 'delivered_at']);
        $conversation = DB::table('conversations')->where(['organization_id' => $organizationId, 'brand_id' => $brandId,
            'id' => $conversationId])->first(['channel_account_id', 'state']);
        $state = $conversation ? json_decode($conversation->state, true) : null;
        $chatId = is_array($state) ? ($state['telegram_chat_id'] ?? null) : null;
        $account = $conversation && is_string($conversation->channel_account_id)
            ? ChannelAccount::withoutGlobalScopes()->where('organization_id', $organizationId)->where('brand_id', $brandId)
                ->where('id', $conversation->channel_account_id)->where('type', 'telegram')->where('status', 'active')->first()
            : null;
        $token = $account?->credentials['token'] ?? null;
        if (! $message || ! $conversation || ! is_string($chatId) || ! preg_match('/^-?[0-9]{1,20}$/D', $chatId) || ! is_string($token)) {
            throw new LogicException('Telegram manager delivery unavailable.');
        }
        if ($message->delivered_at !== null) {
            return;
        }
        $result = $this->call($token, $chatId, $message->text);
        if ($result['ok']) {
            $meta = DB::table('messages')->where('organization_id', $organizationId)->where('brand_id', $brandId)
                ->where('conversation_id', $conversationId)->where('id', $messageId)->value('meta');
            $meta = is_string($meta) ? ((json_decode($meta, true) ?: [])) : [];
            $meta['delivery'] = 'live';
            $meta['telegram_message_id'] = $result['message_id'];
            DB::table('messages')->where('organization_id', $organizationId)->where('brand_id', $brandId)
                ->where('conversation_id', $conversationId)->where('id', $messageId)
                ->update(['meta' => json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);

            return;
        }
        if ($result['retryable']) {
            throw new RuntimeException($result['error']);
        }
        DB::table('messages')->where('organization_id', $organizationId)->where('brand_id', $brandId)
            ->where('conversation_id', $conversationId)->where('id', $messageId)
            ->update(['delivery_error' => mb_substr($result['error'], 0, 120), 'updated_at' => now()]);
    }

    /** @return array{ok: bool, retryable: bool, message_id: ?int, error: ?string} */
    private function call(#[\SensitiveParameter] string $token, string $chatId, string $text): array
    {
        try {
            $response = Http::withoutRedirecting()->connectTimeout(3)->timeout(10)
                ->post('https://api.telegram.org/bot'.$token.'/sendMessage', ['chat_id' => $chatId, 'text' => $text]);
        } catch (ConnectionException $e) {
            return ['ok' => false, 'retryable' => true, 'message_id' => null, 'error' => 'Telegram connection failed: '.$e->getMessage()];
        }
        if ($response->successful() && $response->json('ok') === true) {
            return ['ok' => true, 'retryable' => false, 'message_id' => $response->json('result.message_id'), 'error' => null];
        }
        $code = (int) ($response->json('error_code') ?? $response->status());
        $description = (string) ($response->json('description') ?? ('HTTP '.$response->status()));

        return ['ok' => false, 'retryable' => $code === 429 || $code >= 500, 'message_id' => null, 'error' => $code.': '.$description];
    }
}
