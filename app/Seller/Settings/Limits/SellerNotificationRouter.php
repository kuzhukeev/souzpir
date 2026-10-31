<?php

namespace App\Seller\Settings\Limits;

use App\Models\Membership;
use App\Shell\Notifications\Contracts\NotificationRouter;
use App\Shell\Notifications\DomainEvent;
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
        if ($member->organization_id !== $e['organization_id'] || ! app(RecipientPolicy::class)->permits($member, $e['brand_id'], $e['type'])) {
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
        $timezone = DB::table('brands')->where('organization_id', $e['organization_id'])->where('id', $e['brand_id'])->where('status', 'active')->value('timezone');
        if (! is_string($timezone)) {
            return 'skip';
        }
        $local = CarbonImmutable::now($timezone);
        if (! preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/D', $rule->digest_time, $parts)) {
            throw new LogicException('Invalid digest time.');
        }
        $hour = (int) $parts[1];
        $minute = (int) $parts[2];
        $due = $local->setTime($hour, $minute);
        if ($local->greaterThanOrEqualTo($due)) {
            $due = $due->addDay()->setTime($hour, $minute);
        }
        $batchId = null;
        for ($attempt = 0; $attempt < 366; $attempt++) {
            $dueAt = $due->utc()->format('Y-m-d H:i:sP');
            app(DigestSlotLock::class)->acquire($e['organization_id'], $e['brand_id'], $member->user_id, $channel, $dueAt);
            $batch = DB::table('seller_digest_batches')->where(['organization_id' => $e['organization_id'], 'brand_id' => $e['brand_id'], 'user_id' => $member->user_id, 'channel' => $channel, 'due_at' => $dueAt])->first();
            if (! $batch || $batch->status === 'pending') {
                $batchId = $batch?->id;
                break;
            }
            // A closed or in-flight slot is never reopened: carry the late event forward.
            $due = $due->addDay()->setTime($hour, $minute);
        }
        if ($attempt === 366) {
            throw new LogicException('No open digest slot found.');
        }
        DB::table('seller_digest_items')->insertOrIgnore([
            'id' => (string) Str::ulid(), 'organization_id' => $e['organization_id'], 'brand_id' => $e['brand_id'],
            'event_id' => $e['event_id'], 'user_id' => $member->user_id, 'channel' => $channel,
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
        if (! in_array($channel, ['cabinet', 'email', 'telegram'], true)
            || $member->organization_id !== $e['organization_id']
            || ! app(RecipientPolicy::class)->permits($member, $e['brand_id'], $e['type'])) {
            return false;
        }
        if (! in_array($e['type'], self::EVENTS, true)) {
            return true;
        }
        $rule = DB::table('seller_notification_rules')->where(['organization_id' => $e['organization_id'], 'brand_id' => $e['brand_id'], 'event_key' => $e['type']])->first();

        return ! $rule || ((bool) $rule->{$channel} && $rule->timing === 'immediate' && $this->matches($member, $rule->recipient));
    }
}
