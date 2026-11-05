<?php

namespace App\Shell\Notifications;

use App\Models\Membership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class FakeTimedDelivery
{
    public function dueAt(CarbonImmutable $created, string $timezone, ?string $start, ?string $end, ?string $digest): CarbonImmutable
    {
        $local = $created->setTimezone($timezone);
        if ($digest !== null) {
            $slot = $local->setTimeFromTimeString($digest);
            $local = $slot->lessThanOrEqualTo($local) ? $slot->addDay() : $slot;
        }
        if ($start !== null && $end !== null && $start !== $end) {
            $time = $local->format('H:i');
            $quiet = $start < $end ? $time >= $start && $time < $end : $time >= $start || $time < $end;
            if ($quiet) {
                $next = $local->setTimeFromTimeString($end);
                $local = $next->lessThanOrEqualTo($local) ? $next->addDay() : $next;
            }
        }

        return $local->utc();
    }

    public function drain(): void
    {
        if (config('runtime.notification_driver') !== 'fake') {
            return;
        }
        $deliveries = DB::table('notification_deliveries')->where('status', 'pending')->whereIn('channel', ['email', 'telegram'])->orderBy('created_at')->limit(500)->get();
        $groups = [];
        foreach ($deliveries as $delivery) {
            $member = Membership::withoutGlobalScopes()->where('organization_id', $delivery->organization_id)->where('user_id', $delivery->user_id)->where('status', 'active')->first();
            $organization = DB::table('organizations')->where('id', $delivery->organization_id)->where('status', 'active')->exists();
            $brand = DB::table('brands')->where('id', $delivery->brand_id)->where('organization_id', $delivery->organization_id)->where('status', 'active')->first(['timezone']);
            $outbox = DB::table('outbox_events')->where('id', $delivery->event_id)->where('organization_id', $delivery->organization_id)->where('brand_id', $delivery->brand_id)->first(['envelope']);
            if (! $organization || ! $member || ! $brand || ! $outbox || ! $member->permitsBrand($delivery->brand_id)) {
                continue;
            }
            $event = new DomainEvent(json_decode($outbox->envelope, true, flags: JSON_THROW_ON_ERROR));
            $key = $event->envelope['type'];
            if ($event->envelope['event_id'] !== $delivery->event_id || $event->envelope['organization_id'] !== $delivery->organization_id || $event->envelope['brand_id'] !== $delivery->brand_id || ! $event->isNotification()) {
                continue;
            }
            $settings = DB::table('notification_settings')->where(['organization_id' => $delivery->organization_id, 'brand_id' => $delivery->brand_id, 'user_id' => $delivery->user_id, 'event_key' => $key, 'channel' => $delivery->channel])->first();
            if (! $settings?->enabled || ($delivery->channel === 'telegram' && ! $settings->destination) || ! app(RecipientPolicy::class)->permits($member, $delivery->brand_id, $key)
                || ! app(NotificationRouting::class)->allowsImmediate($event, $member, $delivery->channel)) {
                continue;
            }
            $timing = DB::table('profile_notification_timing_drafts')->where(['organization_id' => $delivery->organization_id, 'brand_id' => $delivery->brand_id, 'user_id' => $delivery->user_id])->first();
            $due = $this->dueAt(CarbonImmutable::parse($delivery->created_at), $brand->timezone, $timing?->quiet_start, $timing?->quiet_end, $timing?->digest_time);
            $now = CarbonImmutable::now('UTC');
            $allowed = $this->dueAt($now, $brand->timezone, $timing?->quiet_start, $timing?->quiet_end, null);
            if ($due->greaterThan($now) || $allowed->greaterThan($now)) {
                continue;
            }
            $groupKey = implode('|', [$delivery->organization_id, $delivery->brand_id, $delivery->user_id, $delivery->channel, $timing?->digest_time !== null ? $due->toIso8601String() : $delivery->id]);
            $groups[$groupKey]['delivery'] = $delivery;
            $groups[$groupKey]['ids'][] = $delivery->id;
            $groups[$groupKey]['titles'][] = EventCatalog::TITLES[$key];
        }
        foreach ($groups as $group) {
            $delivery = $group['delivery'];
            app(FakeTimingTransport::class)->send($delivery->organization_id, $delivery->brand_id, $delivery->user_id, $delivery->channel, $group['titles']);
            DB::table('notification_deliveries')->whereIn('id', $group['ids'])->where('status', 'pending')->update(['status' => 'sent', 'sent_at' => now(), 'updated_at' => now()]);
        }
    }
}
