<?php

namespace App\Seller\Channels\Telegram;

use App\Seller\Channels\Core\ChannelAccount;
use App\Seller\Channels\Core\InboundRecorder;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Followups\FollowupConsent;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class TelegramWebhookController
{
    public function __invoke(Request $request, string $channel, InboundRecorder $recorder): JsonResponse
    {
        abort_if(strlen($request->getContent()) > 262144, 413);
        $account = ChannelAccount::withoutGlobalScopes()->whereKey($channel)->where('type', 'telegram')->where('status', 'active')->firstOrFail();
        abort_unless(app(SellerModuleAccess::class)->enabled($account->organization_id, $account->brand_id), 404);
        $expected = $account->credentials['webhook_secret'] ?? null;
        $secret = $request->header('X-Telegram-Bot-Api-Secret-Token');
        abort_unless(is_string($expected) && is_string($secret) && hash_equals($expected, $secret), 403);
        $payload = $request->json()->all();
        abort_unless(is_int($payload['update_id'] ?? null) && $payload['update_id'] >= 0, 422);
        $message = $payload['message'] ?? null;
        $chatMember = $payload['my_chat_member'] ?? null;
        $context = new TenantContext($account->organization_id, $account->brand_id, source: 'public');

        if (is_array($chatMember)) {
            app(TenantContextRunner::class)->run($context, function () use ($account, $payload, $chatMember, $recorder, $context): void {
                $this->handleChatMemberUpdate($account, $payload, $chatMember, $recorder, $context);
            });

            return response()->json(['ok' => true]);
        }

        app(TenantContextRunner::class)->run($context, function () use ($account, $payload, $message, $recorder, $context): void {
            $settings = $account->settings ?? [];
            $chatType = $message['chat']['type'] ?? null;
            $text = is_string($message['text'] ?? null) ? trim($message['text']) : '';
            if (in_array($chatType, ['group', 'supergroup'], true) && is_int($message['chat']['id'] ?? null)
                && preg_match('/^\/connect ([0-9]{6})$/D', $text, $match)
                && is_string($settings['link_code_hash'] ?? null)
                && isset($settings['link_code_expires_at']) && now()->lessThan(Carbon::parse($settings['link_code_expires_at']))
                && hash_equals($settings['link_code_hash'], hash('sha256', $match[1]))) {
                unset($settings['link_code_hash'], $settings['link_code_expires_at']);
                $account->settings = $settings;
                $account->managers_chat_id = (string) $message['chat']['id'];
                $account->save();
                $recorder->record($context, $account->id, (string) $payload['update_id'], ['update_id' => $payload['update_id'], 'message' => ['text' => '[команда подключения]']]);

                return;
            }
            if (in_array($chatType, ['group', 'supergroup'], true) && str_starts_with($text, '/connect ')) {
                $recorder->record($context, $account->id, (string) $payload['update_id'], ['update_id' => $payload['update_id'], 'message' => ['text' => '[команда подключения]']]);

                return;
            }
            if (! is_array($message) || ($message['chat']['type'] ?? null) !== 'private' || ! is_int($message['chat']['id'] ?? null) || ! is_int($message['message_id'] ?? null)
                || ($message['from']['is_bot'] ?? false) === true || ! is_string($message['text'] ?? null)
                || trim($message['text']) === '') {
                $recorder->record($context, $account->id, (string) $payload['update_id'], $payload);

                return;
            }
            DB::transaction(function () use ($account, $payload, $message, $recorder, $context): void {
                $chatId = (string) $message['chat']['id'];
                $conversation = Conversation::query()->where('channel_account_id', $account->id)
                    ->where('state->telegram_chat_id', $chatId)->first();
                if (! $conversation) {
                    $conversation = new Conversation;
                    $conversation->channel_account_id = $account->id;
                    $conversation->state = ['telegram_chat_id' => $chatId];
                    $conversation->mode = $account->default_mode;
                    $conversation->save();
                }
                $recorder->ingest($context, $account->id, (string) $payload['update_id'], $payload, $conversation->id, $message['text']);
                $conversation->last_client_message_at = now();
                $conversation->save();
            });
        });

        return response()->json(['ok' => true]);
    }

    private function handleChatMemberUpdate(ChannelAccount $account, array $payload, array $chatMember, InboundRecorder $recorder, TenantContext $context): void
    {
        $chatId = $chatMember['chat']['id'] ?? null;
        $status = $chatMember['new_chat_member']['status'] ?? null;
        if (($chatMember['chat']['type'] ?? null) !== 'private' || ! is_int($chatId)) {
            $recorder->record($context, $account->id, (string) $payload['update_id'], $payload);

            return;
        }
        $seen = DB::table('channel_inbound_events')->where('channel_account_id', $account->id)->where('external_id', (string) $payload['update_id'])->exists();
        $recorder->record($context, $account->id, (string) $payload['update_id'], $payload);
        if ($seen || ! in_array($status, ['kicked', 'left'], true)) {
            return;
        }
        $conversation = Conversation::query()->where('channel_account_id', $account->id)->where('state->telegram_chat_id', (string) $chatId)->first();
        if ($conversation) {
            app(FollowupConsent::class)->record($context, $conversation->id, 'revoked', 'dialog', 'Telegram: клиент заблокировал бота или покинул чат');
        }
    }
}
