<?php

namespace App\Shell\Integrations\Webhooks;

use App\Shell\Notifications\DomainEvent;
use Illuminate\Support\Facades\DB;

final class WebhookSnapshot
{
    public function hasSubscribers(string $eventId): bool
    {
        $snapshot = DB::table('webhook_event_snapshots')->where('event_id', $eventId)->value('subscriptions');

        return is_string($snapshot) && json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR) !== [];
    }

    public function record(DomainEvent $event): void
    {
        $e = $event->envelope;
        $ids = [];
        if (isset(WebhookService::EVENTS[$e['type']])) {
            $hooks = DB::table('webhooks')->where('organization_id', $e['organization_id'])->where('brand_id', $e['brand_id'])->get(['id', 'events']);
            foreach ($hooks as $hook) {
                if (in_array($e['type'], json_decode($hook->events, true, 512, JSON_THROW_ON_ERROR), true)) {
                    $ids[] = $hook->id;
                }
            }
        }
        DB::table('webhook_event_snapshots')->insert(['event_id' => $e['event_id'], 'subscriptions' => json_encode($ids, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
    }
}
