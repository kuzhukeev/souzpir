<?php

namespace App\Seller\Channels\Core;

use App\Seller\Conversations\ActiveTenantLock;
use App\Seller\Conversations\CanonicalJson;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use LogicException;

final class InboundRecorder
{
    /** Store an event only. Channel adapters must use ingest for messages. */
    public function record(TenantContext $context, string $channelId, string $externalId, array $payload): ChannelInboundEvent
    {
        $this->validateInput($context, $externalId);

        return app(TenantContextRunner::class)->run($context, fn () => DB::transaction(function () use ($context, $channelId, $externalId, $payload): ChannelInboundEvent {
            ActiveTenantLock::acquire($context);
            $channel = ChannelAccount::whereKey($channelId)->lockForUpdate()->firstOrFail();

            return $this->recordLocked($channel, $externalId, $payload);
        }));
    }

    /** Atomically bind one event to one client message and conversation; sender routing is the adapter's responsibility. */
    public function ingest(TenantContext $context, string $channelId, string $externalId, array $payload, string $conversationId, string $text): Message
    {
        $this->validateInput($context, $externalId);

        return app(TenantContextRunner::class)->run($context, fn () => DB::transaction(function () use ($context, $channelId, $externalId, $payload, $conversationId, $text): Message {
            ActiveTenantLock::acquire($context);
            $channel = ChannelAccount::whereKey($channelId)->lockForUpdate()->firstOrFail();
            $conversation = Conversation::whereKey($conversationId)->where('channel_account_id', $channel->id)->lockForUpdate()->firstOrFail();
            $event = $this->recordLocked($channel, $externalId, $payload);
            $existing = Message::where('external_event_id', $event->id)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->conversation_id !== $conversation->id || ! hash_equals($existing->text, $text)) {
                    abort(409, 'Inbound event already bound to another message.');
                }

                return $existing;
            }

            return Message::create(['conversation_id' => $conversation->id, 'channel_account_id' => $channel->id, 'external_event_id' => $event->id, 'role' => 'client', 'text' => $text, 'meta' => []]);
        }));
    }

    private function validateInput(TenantContext $context, string $externalId): void
    {
        if ($context->brandId === null || $externalId === '' || mb_strlen($externalId) > 255) {
            throw new LogicException('Invalid inbound event.');
        }
    }

    private function recordLocked(ChannelAccount $channel, string $externalId, array $payload): ChannelInboundEvent
    {
        $existing = ChannelInboundEvent::where('channel_account_id', $channel->id)->where('external_id', $externalId)->lockForUpdate()->first();
        if ($existing) {
            if (! hash_equals(CanonicalJson::hash($existing->payload), CanonicalJson::hash($payload))) {
                abort(409, 'Inbound event payload conflict.');
            }

            return $existing;
        }

        return ChannelInboundEvent::create(['channel_account_id' => $channel->id, 'external_id' => $externalId, 'payload' => $payload]);
    }
}
