<?php

namespace App\Seller\Settings\Limits;

use App\Models\Membership;
use App\Models\User;
use App\Shell\Notifications\DomainEvent;
use App\Shell\Notifications\EventCatalog;
use App\Shell\Notifications\FakeTimedDelivery;
use App\Shell\Notifications\FakeTimingTransport;
use App\Shell\Notifications\RecipientPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final class DigestService
{
    /** Form exactly one batch for each due slot; the database owns the idempotency key. */
    public function assemble(): void
    {
        $groups = DB::table('seller_digest_items')->whereNull('batch_id')->where('due_at', '<=', now())
            ->select(['organization_id', 'brand_id', 'user_id', 'channel', 'slot_date', 'due_at'])->distinct()->orderBy('due_at')->limit(100)->get();
        foreach ($groups as $group) {
            DB::transaction(function () use ($group): void {
                $slot = ['organization_id' => $group->organization_id, 'brand_id' => $group->brand_id, 'user_id' => $group->user_id, 'channel' => $group->channel, 'slot_date' => $group->slot_date];
                app(DigestSlotLock::class)->acquire($group->organization_id, $group->brand_id, $group->user_id, $group->channel, $group->slot_date);
                DB::table('seller_digest_batches')->insertOrIgnore([...$slot, 'due_at' => $group->due_at, 'id' => (string) Str::ulid(), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
                $batch = DB::table('seller_digest_batches')->where($slot)->lockForUpdate()->first();
                if (! $batch || $batch->status !== 'pending') {
                    return;
                }
                DB::table('seller_digest_items')->where($slot)->whereNull('batch_id')->update(['batch_id' => $batch->id, 'updated_at' => now()]);
            });
        }
    }

    /** Never automatically retry failed, uncertain or interrupted sending batches. */
    public function deliver(string $id): void
    {
        $snapshot = DB::table('seller_digest_batches')->where('id', $id)->first();
        if (! $snapshot || $snapshot->status !== 'pending') {
            return;
        }
        $fake = config('runtime.notification_driver') === 'fake';
        if ($snapshot->channel !== 'cabinet' && ! $fake && ! config('features.E0-04.defaults.external_delivery', false)) {
            return;
        }
        // Fake external batches are consolidated by FakeTimedDelivery.
        if ($snapshot->channel !== 'cabinet' && $fake) {
            return;
        }
        if (! $fake && $snapshot->channel !== 'cabinet' && DB::transactionLevel() !== 0) {
            throw new LogicException('External digest delivery cannot run inside a transaction.');
        }
        $claimed = DB::transaction(function () use ($id, $snapshot, $fake): ?array {
            // Consistent with web actions: user -> organization -> brand -> membership -> batch.
            // FK inserts need KEY SHARE while this delivery waits on the organization.
            $user = User::whereKey($snapshot->user_id)->lock('for no key update')->first();
            $org = DB::table('organizations')->where('id', $snapshot->organization_id)->where('status', 'active')->lockForUpdate()->first();
            $brand = DB::table('brands')->where('id', $snapshot->brand_id)->where('organization_id', $snapshot->organization_id)->where('status', 'active')->lockForUpdate()->first();
            $member = Membership::withoutGlobalScopes()->where('organization_id', $snapshot->organization_id)->where('user_id', $snapshot->user_id)->where('status', 'active')->lockForUpdate()->first();
            app(DigestSlotLock::class)->acquire($snapshot->organization_id, $snapshot->brand_id, $snapshot->user_id, $snapshot->channel, $snapshot->slot_date);
            $batch = DB::table('seller_digest_batches')->where('id', $id)->lockForUpdate()->first();
            if (! $batch || $batch->status !== 'pending' || now()->lessThan($batch->due_at)) {
                return null;
            }
            if (! $user || ! $org || ! $brand || ! $member || ! $member->permitsBrand($batch->brand_id) || ! app(SellerModuleAccess::class)->enabled($batch->organization_id, $batch->brand_id)) {
                $this->update($id, 'cancelled', 'access_revoked');

                return null;
            }
            if ($fake && $batch->channel !== 'cabinet') {
                $timing = DB::table('profile_notification_timing_drafts')->where(['organization_id' => $batch->organization_id, 'brand_id' => $batch->brand_id, 'user_id' => $batch->user_id])->first();
                $now = CarbonImmutable::now('UTC');
                if (app(FakeTimedDelivery::class)->dueAt($now, $brand->timezone, $timing?->quiet_start, $timing?->quiet_end, null)->greaterThan($now)) {
                    return null;
                }
            }
            $titles = [];
            $eventKeys = [];
            $destinations = [];
            $items = DB::table('seller_digest_items')->where('batch_id', $id)->orderBy('event_id')->get();
            foreach ($items as $item) {
                $row = DB::table('outbox_events')->where('id', $item->event_id)->where('organization_id', $batch->organization_id)->where('brand_id', $batch->brand_id)->first();
                if (! $row) {
                    continue;
                }
                $event = new DomainEvent(json_decode($row->envelope, true, 512, JSON_THROW_ON_ERROR));
                $e = $event->envelope;
                if ($e['organization_id'] !== $batch->organization_id || $e['brand_id'] !== $batch->brand_id || ! in_array($e['type'], SellerNotificationRouter::EVENTS, true)) {
                    continue;
                }
                $rule = DB::table('seller_notification_rules')->where(['organization_id' => $batch->organization_id, 'brand_id' => $batch->brand_id, 'event_key' => $e['type']])->first();
                $personal = DB::table('notification_settings')->where(['organization_id' => $batch->organization_id, 'brand_id' => $batch->brand_id, 'user_id' => $batch->user_id, 'event_key' => $e['type'], 'channel' => $batch->channel])->first();
                if (! app(RecipientPolicy::class)->permits($member, $batch->brand_id, $e['type']) || ! $rule || $rule->timing !== 'digest' || ! $rule->{$batch->channel}
                    || ! app(SellerNotificationRouter::class)->matches($member, $rule->recipient)
                    || ($personal && ! $personal->enabled) || (! $personal && $batch->channel !== 'cabinet')) {
                    continue;
                }
                if ($batch->channel === 'telegram') {
                    if (! $personal?->destination) {
                        continue;
                    }
                    try {
                        $destinations[] = Crypt::decryptString($personal->destination);
                    } catch (Throwable) {
                        $this->update($id, 'failed', 'invalid_destination');

                        return null;
                    }
                }
                $titles[] = EventCatalog::TITLES[$e['type']];
                $eventKeys[] = $e['type'];
            }
            if ($titles === []) {
                $this->update($id, 'cancelled', 'no_eligible_events');

                return null;
            }
            if ($batch->channel === 'cabinet') {
                DB::table('seller_digest_cabinet_notifications')->insert(['batch_id' => $id, 'organization_id' => $batch->organization_id, 'brand_id' => $batch->brand_id, 'user_id' => $batch->user_id, 'titles' => json_encode($titles, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'event_keys' => json_encode($eventKeys, JSON_THROW_ON_ERROR), 'created_at' => now()]);
                $this->update($id, 'sent', null, true);

                return null;
            }
            if ($fake) {
                $this->update($id, 'sending', null);

                return ['channel' => $batch->channel, 'titles' => $titles, 'organization' => $batch->organization_id, 'brand' => $batch->brand_id, 'user' => $batch->user_id];
            }
            if ($batch->channel === 'email') {
                if (! is_string($user->email) || $user->email === '' || in_array(config('mail.default'), ['log', 'array'], true)) {
                    $this->update($id, 'failed', 'transport_unavailable');

                    return null;
                }
                $destination = $user->email;
            } else {
                $destinations = array_values(array_unique($destinations));
                if (count($destinations) !== 1 || ! is_string(config('features.E0-04.defaults.telegram_bot_token')) || config('features.E0-04.defaults.telegram_bot_token') === '') {
                    $this->update($id, 'failed', 'transport_unavailable');

                    return null;
                }
                $destination = $destinations[0];
            }
            $this->update($id, 'sending', null);

            return ['channel' => $batch->channel, 'destination' => $destination, 'titles' => $titles];
        });
        if (! $claimed) {
            return;
        }
        try {
            if ($fake) {
                app(FakeTimingTransport::class)->send($claimed['organization'], $claimed['brand'], $claimed['user'], $claimed['channel'], $claimed['titles']);
            } else {
                app(DigestTransport::class)->send($claimed['channel'], $claimed['destination'], $claimed['titles']);
            }
            DB::table('seller_digest_batches')->where('id', $id)->where('status', 'sending')->update(['status' => 'sent', 'sent_at' => now(), 'last_error' => null, 'updated_at' => now()]);
        } catch (Throwable) {
            DB::table('seller_digest_batches')->where('id', $id)->where('status', 'sending')->update(['status' => 'uncertain', 'last_error' => 'delivery_uncertain', 'updated_at' => now()]);
        }
    }

    public function run(): void
    {
        if (config('features.E6-07.defaults.digest_enabled', false)) {
            $this->assemble();
            $batches = DB::table('seller_digest_batches')->where('status', 'pending')->orderBy('due_at');
            if (! config('features.E0-04.defaults.external_delivery', false) || config('runtime.notification_driver') === 'fake') {
                $batches->where('channel', 'cabinet');
            }
            foreach ($batches->limit(100)->pluck('id') as $id) {
                $this->deliver($id);
            }
        }
        $tests = DB::table('seller_test_deliveries')->where('status', 'pending')->orderBy('created_at');
        if (! config('features.E0-04.defaults.external_delivery', false) || config('runtime.notification_driver') === 'fake') {
            $tests->where('channel', 'cabinet');
        }
        foreach ($tests->limit(100)->pluck('id') as $id) {
            app(TestDeliveryService::class)->deliver($id);
        }
    }

    private function update(string $id, string $status, ?string $error, bool $sent = false): void
    {
        DB::table('seller_digest_batches')->where('id', $id)->update(['status' => $status, 'last_error' => $error, 'sent_at' => $sent ? now() : null, 'updated_at' => now()]);
    }
}
