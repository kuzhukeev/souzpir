<?php

namespace App\Seller\Channels\Core;

use App\Seller\Conversations\CanonicalJson;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use LogicException;

final class InboundRecorder
{
    public function record(TenantContext $context, string $channelId, string $externalId, array $payload): ChannelInboundEvent
    {
        if ($context->brandId === null || $externalId === '' || mb_strlen($externalId) > 255) {
            throw new LogicException('Invalid inbound event.');
        }

        return app(TenantContextRunner::class)->run($context, fn () => DB::transaction(function () use ($channelId, $externalId, $payload): ChannelInboundEvent {
            $channel = ChannelAccount::whereKey($channelId)->lockForUpdate()->firstOrFail();
            $existing = ChannelInboundEvent::where('channel_account_id', $channel->id)->where('external_id', $externalId)->first();
            if ($existing) {
                if (! hash_equals(CanonicalJson::hash($existing->payload), CanonicalJson::hash($payload))) {
                    abort(409, 'Идентификатор входящего события уже использован.');
                }

                return $existing;
            }

            return ChannelInboundEvent::create(['channel_account_id' => $channel->id, 'external_id' => $externalId, 'payload' => $payload]);
        }));
    }
}
