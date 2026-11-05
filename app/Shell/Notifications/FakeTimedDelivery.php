<?php

namespace App\Shell\Notifications;

use App\Models\Membership;
use App\Seller\Settings\Limits\DigestService;
use App\Seller\Settings\Limits\SellerNotificationRouter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        if (config('features.E6-07.defaults.digest_enabled', false)) {
            app(DigestService::class)->assemble();
            $this->transferSellerBatches();
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
            $digest = $timing?->digest_time ?? $this->sellerDigestTime($member, $delivery);
            $due = $this->dueAt(CarbonImmutable::parse($delivery->created_at), $brand->timezone, $timing?->quiet_start, $timing?->quiet_end, $digest);
            $now = CarbonImmutable::now('UTC');
            $allowed = $this->dueAt($now, $brand->timezone, $timing?->quiet_start, $timing?->quiet_end, null);
            if ($due->greaterThan($now) || $allowed->greaterThan($now)) {
                continue;
            }
            $groupKey = implode('|', [$delivery->organization_id, $delivery->brand_id, $delivery->user_id, $delivery->channel, $digest !== null ? $due->toIso8601String() : $delivery->id]);
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

    private function sellerDigestTime(Membership $member, object $delivery): ?string
    {
        if (! config('features.E6-07.defaults.digest_enabled', false)) {
            return null;
        }
        $rules = DB::table('seller_notification_rules')->where(['organization_id' => $delivery->organization_id, 'brand_id' => $delivery->brand_id, 'timing' => 'digest', $delivery->channel => true])->get();
        foreach ($rules as $rule) {
            $setting = DB::table('notification_settings')->where(['organization_id' => $delivery->organization_id, 'brand_id' => $delivery->brand_id, 'user_id' => $delivery->user_id, 'event_key' => $rule->event_key, 'channel' => $delivery->channel])->first();
            if ($setting?->enabled && app(RecipientPolicy::class)->permits($member, $delivery->brand_id, $rule->event_key) && app(SellerNotificationRouter::class)->matches($member, $rule->recipient)) {
                return DB::table('seller_digest_preferences')->where(['organization_id' => $delivery->organization_id, 'brand_id' => $delivery->brand_id, 'user_id' => $delivery->user_id])->value('digest_time') ?? '09:00';
            }
        }

        return null;
    }

    private function transferSellerBatches(): void
    {
        $batches = DB::table('seller_digest_batches')->where('status', 'pending')->whereIn('channel', ['email', 'telegram'])->where('due_at', '<=', now())->orderBy('due_at')->limit(100)->get();
        foreach ($batches as $batch) {
            DB::transaction(function () use ($batch): void {
                $items = DB::table('seller_digest_items')->where('batch_id', $batch->id)->get();
                foreach ($items as $item) {
                    $envelope = DB::table('outbox_events')->where(['id' => $item->event_id, 'organization_id' => $batch->organization_id, 'brand_id' => $batch->brand_id])->value('envelope');
                    $event = is_string($envelope) ? json_decode($envelope, true) : null;
                    $type = is_array($event) ? ($event['type'] ?? null) : null;
                    $rule = DB::table('seller_notification_rules')->where(['organization_id' => $batch->organization_id, 'brand_id' => $batch->brand_id, 'event_key' => $type, 'timing' => 'digest'])->first();
                    if (! $rule || ! in_array($type, SellerNotificationRouter::EVENTS, true)) {
                        continue;
                    }
                    DB::table('notification_deliveries')->insertOrIgnore(['id' => (string) Str::ulid(), 'organization_id' => $batch->organization_id, 'brand_id' => $batch->brand_id, 'user_id' => $batch->user_id, 'channel' => $batch->channel, 'event_id' => $item->event_id, 'status' => 'pending', 'attempts' => 0, 'created_at' => $item->created_at, 'updated_at' => now()]);
                }
                DB::table('seller_digest_batches')->where('id', $batch->id)->where('status', 'pending')->update(['status' => 'cancelled', 'last_error' => 'moved_to_fake_digest', 'updated_at' => now()]);
            });
        }
    }
}
