<?php

namespace App\Shell\Notifications;

use Illuminate\Support\Facades\DB;

final class OutboxCursor
{
    /** Reserve a bounded page while holding only the cursor lock, never during processing. */
    public function reserve(int $limit = 100): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Notification scanning must run outside a transaction.');
        }
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Invalid notification page size.');
        }

        return DB::transaction(function () use ($limit): array {
            DB::table('notification_outbox_cursors')->insertOrIgnore(['name' => 'notifications', 'updated_at' => now()]);
            $cursor = DB::table('notification_outbox_cursors')->where('name', 'notifications')->lockForUpdate()->first();
            $query = DB::table('outbox_events')->whereNull('processed_at');
            if ($cursor->last_created_at !== null) {
                $query->where(function ($q) use ($cursor): void {
                    $q->where('created_at', '>', $cursor->last_created_at)
                        ->orWhere(fn ($q) => $q->where('created_at', $cursor->last_created_at)->where('id', '>', $cursor->last_event_id));
                });
            }
            $rows = $query->orderBy('created_at')->orderBy('id')->limit($limit)->get(['id', 'created_at']);
            if ($rows->isEmpty() && $cursor->last_created_at !== null) {
                $rows = DB::table('outbox_events')->whereNull('processed_at')->orderBy('created_at')->orderBy('id')->limit($limit)->get(['id', 'created_at']);
            }
            $last = $rows->last();
            DB::table('notification_outbox_cursors')->where('name', 'notifications')->update([
                'last_created_at' => $last?->created_at, 'last_event_id' => $last?->id, 'updated_at' => now(),
            ]);

            return $rows->pluck('id')->all();
        });
    }
}
