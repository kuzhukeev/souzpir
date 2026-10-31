<?php

namespace App\Shell\Integrations\Webhooks;

use App\Shell\Notifications\DomainEvent;
use Illuminate\Support\Facades\DB;
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
            // created_at does not prove the order of concurrent commits. Never
            // select recipients, create deliveries or consume the event until
            // the shared, immutable subscription snapshot is implemented.
            throw new LogicException('Webhook subscription snapshot unavailable.');
        });
    }
}
