<?php

namespace App\Shell\Notifications;

use App\Shell\Notifications\Contracts\NotificationDispatcher;
use App\Shell\Notifications\Jobs\ProcessNotificationEvent;
use Illuminate\Support\Facades\DB;
use LogicException;

final class OutboxDispatcher implements NotificationDispatcher
{
    public function enqueue(DomainEvent $event): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('An event must be enqueued within the business transaction.');
        }
        $e = $event->envelope;
        $data = json_encode($e, JSON_THROW_ON_ERROR);
        $inserted = DB::table('outbox_events')->insertOrIgnore([
            'id' => $e['event_id'], 'organization_id' => $e['organization_id'], 'brand_id' => $e['brand_id'],
            'envelope' => $data, 'created_at' => now(),
        ]);
        if (! $inserted) {
            $existing = DB::table('outbox_events')->where('id', $e['event_id'])->value('envelope');
            if (json_decode($existing, true, 512, JSON_THROW_ON_ERROR) != $e) {
                throw new LogicException('Event identifier already has different data.');
            }

            return;
        }
        ProcessNotificationEvent::dispatch($e['event_id'])->afterCommit();
    }
}
