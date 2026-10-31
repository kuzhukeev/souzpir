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
            if (array_key_exists($e['type'], WebhookService::EVENTS)) {
                $hooks = DB::table('webhooks')->where('organization_id', $e['organization_id'])->where('brand_id', $e['brand_id'])
                    ->where('created_at', '<=', $row->created_at)->get();
                foreach ($hooks as $hook) {
                    if (! in_array($e['type'], json_decode($hook->events, true, 512, JSON_THROW_ON_ERROR), true)) {
                        continue;
                    }
                    $deliveryId = (string) Str::ulid();
                    $inserted = DB::table('webhook_deliveries')->insertOrIgnore([
                        'id' => $deliveryId, 'organization_id' => $e['organization_id'], 'brand_id' => $e['brand_id'],
                        'webhook_id' => $hook->id, 'event_id' => $eventId, 'event' => $e['type'], 'payload' => $row->envelope,
                        'status' => 'pending', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    if ($inserted && config('features.E1-10.defaults.webhook_delivery', false)) {
                        DeliverWebhook::dispatch($deliveryId)->afterCommit();
                    }
                }
            }
            DB::table('consumed_events')->insert(['id' => (string) Str::ulid(), 'organization_id' => $e['organization_id'],
                'event_id' => $eventId, 'consumer' => 'integrations.webhooks.v1', 'created_at' => now()]);
        });
    }
}
