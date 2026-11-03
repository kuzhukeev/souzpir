<?php

namespace App\Seller\Runtime\Delivery;

use App\Seller\Runtime\Contracts\ChannelDelivery;
use App\Seller\Runtime\Contracts\DeliveryCommand;
use App\Seller\Runtime\Contracts\DeliveryReceipt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Fake sink only: records no message text and makes no network calls. */
final class FakeChannelDelivery implements ChannelDelivery
{
    public function send(DeliveryCommand $command): DeliveryReceipt
    {
        $tenant = $command->tenant;
        if (! $tenant->organizationId || ! $tenant->brandId || $command->channel !== 'fake'
            || ! Str::isUlid($command->conversationId) || ! Str::isUlid($command->messageId)
            || $command->idempotencyKey === '' || strlen($command->idempotencyKey) > 512) {
            throw new LogicException('Invalid fake delivery.');
        }
        $message = DB::table('messages')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
            ->where('conversation_id', $command->conversationId)->where('id', $command->messageId)->where('role', 'agent')->exists();
        if (! $message) {
            throw new LogicException('Outbound message unavailable.');
        }
        $key = 'seller-fake-delivery:'.hash('sha256', $tenant->organizationId.'|'.$tenant->brandId.'|'.$command->idempotencyKey);
        $fingerprint = hash('sha256', $command->conversationId.'|'.$command->messageId.'|'.$command->channel);
        if (Cache::add($key, $fingerprint, now()->addDays(30)) === false && ! hash_equals((string) Cache::get($key), $fingerprint)) {
            throw new LogicException('Delivery key conflict.');
        }

        return new DeliveryReceipt('fake_sent', $command->messageId);
    }
}
