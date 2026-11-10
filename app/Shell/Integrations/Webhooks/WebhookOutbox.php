<?php

namespace App\Shell\Integrations\Webhooks;

use App\Shell\Notifications\DomainEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** An independent outbox consumer: it never changes notification processor state. */
final class WebhookOutbox
{
    public function consume(string $eventId): void
    {
        DB::transaction(function () use ($eventId): void {
            $row = DB::table('outbox_events')->where('id', $eventId)->lockForUpdate()->first();
            if (! $row || DB::table('consumed_events')->where('consumer', 'integrations.webhooks.v1')->where('event_id', $eventId)->exists()) {
                return;
            }
            $event = new DomainEvent(json_decode($row->envelope, true, 512, JSON_THROW_ON_ERROR));
            $e = $event->envelope;
            if ($e['event_id'] !== $row->id || $e['event_id'] !== $eventId
                || $e['organization_id'] !== $row->organization_id || $e['brand_id'] !== $row->brand_id) {
                throw new LogicException('Webhook event identity mismatch.');
            }
            $snapshot = DB::table('webhook_event_snapshots')->where('event_id', $eventId)->value('subscriptions');
            if (! is_string($snapshot)) {
                throw new LogicException('Webhook subscription snapshot unavailable.');
            }
            $ids = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($ids)) {
                throw new LogicException('Invalid webhook subscription snapshot.');
            }
            foreach ($ids as $id) {
                $hook = DB::table('webhooks')->where('id', $id)->where('organization_id', $e['organization_id'])->where('brand_id', $e['brand_id'])->first();
                if (! $hook) {
                    continue;
                }
                $deliveryId = (string) Str::ulid();
                DB::table('webhook_deliveries')->insert(['id' => $deliveryId, 'organization_id' => $e['organization_id'], 'brand_id' => $e['brand_id'], 'webhook_id' => $id, 'event_id' => $eventId, 'event' => $e['type'], 'payload' => json_encode($e, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'status' => 'pending', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now()]);
                if (WebhookDelivery::available()) {
                    DeliverWebhook::dispatch($deliveryId)->afterCommit();
                } else {
                    DeliverFakeWebhook::dispatch($deliveryId)->afterCommit();
                }
            }
            DB::table('consumed_events')->insert(['id' => (string) Str::ulid(), 'organization_id' => $e['organization_id'], 'event_id' => $eventId, 'consumer' => 'integrations.webhooks.v1', 'created_at' => now()]);
        });
    }
}
