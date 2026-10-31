<?php

namespace App\Seller\Settings\Limits;

use App\Models\Membership;
use App\Shell\Notifications\Contracts\NotificationRouter;
use App\Shell\Notifications\DomainEvent;
use App\Shell\Notifications\NotificationDeferred;
use App\Shell\Notifications\RecipientPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Called from the notification outbox transaction, after the shared policy and personal opt-out. */
final class SellerNotificationRouter implements NotificationRouter
{
    public const EVENTS = [
        'seller.conversation.handed_off', 'seller.promise.overdue', 'seller.unknown_question.logged',
        'seller.supervisor.completed', 'seller.supervisor.blocking_issue', 'seller.channel.disconnected',
        'seller.quota.threshold_reached',
    ];

    public function route(DomainEvent $event, Membership $member, string $channel): string
    {
        $e = $event->envelope;
        if (! in_array($e['type'], self::EVENTS, true)) {
            return 'immediate';
        }
        if (DB::transactionLevel() < 1 || ! in_array($channel, ['cabinet', 'email', 'telegram'], true)) {
            throw new LogicException('Seller notification routing requires the outbox transaction and a known channel.');
        }
        $member = $this->lockedMember($e['organization_id'], $e['brand_id'], $member);
        if (! $member || ! app(RecipientPolicy::class)->permits($member, $e['brand_id'], $e['type'])) {
            return 'skip';
        }
        $rule = DB::table('seller_notification_rules')->where(['organization_id' => $e['organization_id'], 'brand_id' => $e['brand_id'], 'event_key' => $e['type']])->first();
        if (! $rule) {
            return 'immediate';
        }
        if (! $rule->{$channel} || ! $this->matches($member, $rule->recipient)) {
            return 'skip';
        }
        if ($rule->timing === 'immediate') {
            return 'immediate';
        }
        if ($rule->timing !== 'digest') {
            throw new LogicException('Unknown seller notification timing.');
        }
        if (! config('features.E6-07.defaults.digest_enabled', false)) {
            throw new NotificationDeferred('digest_disabled');
        }
        $timezone = DB::table('brands')->where('organization_id', $e['organization_id'])->where('id', $e['brand_id'])->where('status', 'active')->value('timezone');
        if (! is_string($timezone)) {
            return 'skip';
        }
        $local = CarbonImmutable::now('UTC')->setTimezone($timezone);
        $time = DB::table('seller_digest_preferences')->where(['organization_id' => $e['organization_id'], 'brand_id' => $e['brand_id'], 'user_id' => $member->user_id])->value('digest_time') ?? '09:00';
        if (! is_string($time) || ! preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/D', $time, $parts)) {
            throw new LogicException('Invalid digest time.');
        }
        $hour = (int) $parts[1];
        $minute = (int) $parts[2];
        $calendar = app(DigestLocalTime::class);
        $date = $local->toDateString();
        $batchId = null;
        for ($attempt = 0; $attempt < 366; $attempt++) {
            $slot = ['organization_id' => $e['organization_id'], 'brand_id' => $e['brand_id'], 'user_id' => $member->user_id, 'channel' => $channel, 'slot_date' => $date];
            app(DigestSlotLock::class)->acquire($e['organization_id'], $e['brand_id'], $member->user_id, $channel, $date);
            $batch = DB::table('seller_digest_batches')->where($slot)->first();
            if ($batch && $batch->status !== 'pending') {
                $date = $calendar->nextDate($date);

                continue;
            }
            $existing = DB::table('seller_digest_items')->where($slot)->orderBy('created_at')->first();
            if ($batch || $existing) {
                $dueAt = $batch?->due_at ?? $existing->due_at;
                $batchId = $batch?->id;
                break;
            }
            $due = $calendar->resolve($date, $hour, $minute, $timezone);
            // Compare absolute instants: both dates may have the same wall clock but different DST offsets.
            if ($local->timestamp >= $due->timestamp) {
                $date = $calendar->nextDate($date);

                continue;
            }
            $dueAt = $due->utc()->format('Y-m-d H:i:sP');
            break;
        }
        if ($attempt === 366) {
            throw new LogicException('No open digest slot found.');
        }
        DB::table('seller_digest_items')->insertOrIgnore([
            'id' => (string) Str::ulid(), ...$slot, 'event_id' => $e['event_id'],
            'due_at' => $dueAt, 'batch_id' => $batchId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return 'digest';
    }

    public function matches(Membership $member, string $recipient): bool
    {
        return match ($recipient) {
            'all' => true,
            'owner', 'admin' => $member->role === $recipient,
            'editor' => $member->role === 'member' && ($member->settings['sales_manager'] ?? false) === false,
            'manager' => $member->role === 'member' && ($member->settings['sales_manager'] ?? false) === true,
            default => false,
        };
    }

    /** Read-only recheck immediately before the shared external transport starts. */
    public function allowsImmediate(DomainEvent $event, Membership $member, string $channel): bool
    {
        $e = $event->envelope;
        if (! in_array($e['type'], self::EVENTS, true)) {
            return true;
        }
        if (! in_array($channel, ['cabinet', 'email', 'telegram'], true)) {
            return false;
        }

        return DB::transaction(function () use ($e, $member, $channel): bool {
            $fresh = $this->lockedMember($e['organization_id'], $e['brand_id'], $member);
            if (! $fresh || ! app(RecipientPolicy::class)->permits($fresh, $e['brand_id'], $e['type'])) {
                return false;
            }
            $rule = DB::table('seller_notification_rules')->where(['organization_id' => $e['organization_id'], 'brand_id' => $e['brand_id'], 'event_key' => $e['type']])->first();

            return ! $rule || ((bool) $rule->{$channel} && $rule->timing === 'immediate' && $this->matches($fresh, $rule->recipient));
        });
    }

    /** Caller holds a transaction; organization lock serializes module and tenant changes. */
    private function lockedMember(string $organization, string $brand, Membership $member): ?Membership
    {
        if ($member->organization_id !== $organization
            || ! DB::table('organizations')->where('id', $organization)->where('status', 'active')->lockForUpdate()->first()
            || ! DB::table('brands')->where('id', $brand)->where('organization_id', $organization)->where('status', 'active')->lockForUpdate()->first()) {
            return null;
        }
        $fresh = Membership::withoutGlobalScopes()->whereKey($member->id)->where('organization_id', $organization)->where('user_id', $member->user_id)->where('status', 'active')->lockForUpdate()->first();
        if (! $fresh || ! $fresh->permitsBrand($brand) || ! app(SellerModuleAccess::class)->enabled($organization, $brand)) {
            return null;
        }

        return $fresh;
    }
}
