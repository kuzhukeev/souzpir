<?php

namespace App\Shell\Notifications;

use App\Models\Membership;
use App\Seller\Channels\Planned\ContactReminderValidity;
use App\Shell\Notifications\Jobs\DeliverNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NotificationProcessor
{
    public function process(string $eventId): void
    {
        DB::transaction(function () use ($eventId): void {
            $row = DB::table('outbox_events')->where('id', $eventId)->lockForUpdate()->first();
            if (! $row || $row->processed_at !== null) {
                return;
            }
            $event = new DomainEvent(json_decode($row->envelope, true, 512, JSON_THROW_ON_ERROR));
            if ($event->isNotification()) {
                $e = $event->envelope;
                app(MeasurementSubscriptionDelivery::class)->deliver($event);
                $members = Membership::withoutGlobalScopes()->where('organization_id', $e['organization_id'])->where('status', 'active')->get();
                foreach ($members as $member) {
                    if ($e['type'] === 'seller.contact.reminder' && (config('runtime.notification_driver') !== 'fake' || $member->user_id !== $e['data']['user_id']
                        || ! app(ContactReminderValidity::class)->valid($e['data']['reminder_id'], $e['organization_id'], $e['brand_id'], $member->user_id))) {
                        continue;
                    }
                    if (! app(RecipientPolicy::class)->permits($member, $e['brand_id'], $e['type'])) {
                        continue;
                    }
                    foreach (['cabinet', 'email', 'telegram'] as $channel) {
                        $setting = DB::table('notification_settings')->where(['organization_id' => $e['organization_id'], 'brand_id' => $e['brand_id'], 'user_id' => $member->user_id, 'event_key' => $e['type'], 'channel' => $channel])->first();
                        if (($setting && ! $setting->enabled) || (! $setting && $channel !== 'cabinet')) {
                            continue;
                        }
                        if (app(NotificationRouting::class)->route($event, $member, $channel) !== 'immediate') {
                            continue;
                        }
                        $deliveryId = (string) Str::ulid();
                        $inserted = DB::table('notification_deliveries')->insertOrIgnore(['id' => $deliveryId, 'organization_id' => $e['organization_id'], 'brand_id' => $e['brand_id'], 'event_id' => $eventId, 'user_id' => $member->user_id, 'channel' => $channel, 'status' => $channel === 'cabinet' ? 'sent' : 'pending', 'attempts' => 0, 'sent_at' => $channel === 'cabinet' ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
                        if ($inserted && $channel !== 'cabinet' && config('features.E0-04.defaults.external_delivery') && config('runtime.notification_driver') !== 'fake') {
                            DeliverNotification::dispatch($deliveryId)->afterCommit();
                        }
                        if ($inserted && $channel === 'cabinet') {
                            DB::table('cabinet_notifications')->insert(['id' => (string) Str::ulid(), 'organization_id' => $e['organization_id'], 'brand_id' => $e['brand_id'], 'user_id' => $member->user_id, 'delivery_id' => $deliveryId, 'event_key' => $e['type'], 'title' => EventCatalog::TITLES[$e['type']], 'created_at' => now()]);
                        }
                    }
                }
            }
            DB::table('consumed_events')->insertOrIgnore(['id' => (string) Str::ulid(), 'organization_id' => $row->organization_id, 'event_id' => $eventId, 'consumer' => 'cabinet.notifications.v1', 'created_at' => now()]);
            DB::table('outbox_events')->where('id', $eventId)->update(['processed_at' => now()]);
        });
    }
}
