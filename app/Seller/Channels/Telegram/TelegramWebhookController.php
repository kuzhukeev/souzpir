<?php

namespace App\Seller\Channels\Telegram;

use App\Seller\Channels\Core\ChannelAccount;
use App\Seller\Channels\Core\InboundRecorder;
use App\Seller\Conversations\Models\Conversation;
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
        $context = new TenantContext($account->organization_id, $account->brand_id, source: 'public');

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
}
