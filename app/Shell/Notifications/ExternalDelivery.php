<?php

namespace App\Shell\Notifications;

use App\Models\Membership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

final class ExternalDelivery
{
    public function send(string $id): void
    {
        if (! $this->enabled()) {
            return;
        }
        $delivery = DB::table('notification_deliveries')->where('id', $id)->first();
        if (! $delivery || $this->defer($delivery)) {
            return;
        }
        $this->transmit([$id]);
    }

    /** Sends due deliveries; personal quiet hours postpone them, a personal digest groups them per brand, user and channel. */
    public function drain(array $statuses, int $limit = 500): void
    {
        if (! $this->enabled()) {
            return;
        }
        $rows = DB::table('notification_deliveries')->whereIn('status', [...$statuses, 'deferred'])->whereIn('channel', ['email', 'telegram'])
            ->orderBy('created_at')->orderBy('id')->limit($limit)->get();
        $groups = [];
        foreach ($rows as $delivery) {
            $timing = $this->timing($delivery);
            if ($timing !== null && $this->defer($delivery, $timing)) {
                continue;
            }
            $key = $timing !== null && $timing['digest'] !== null
                ? implode('|', [$delivery->organization_id, $delivery->brand_id, $delivery->user_id, $delivery->channel, $this->dueAt($delivery, $timing)->toIso8601String()])
                : $delivery->id;
            $groups[$key][] = $delivery->id;
        }
        foreach ($groups as $ids) {
            $this->transmit($ids);
        }
    }

    /** @return array{start: ?string, end: ?string, digest: ?string, timezone: string}|null */
    public function timing(object $delivery): ?array
    {
        $row = DB::table('profile_notification_timing_drafts')->where(['organization_id' => $delivery->organization_id, 'brand_id' => $delivery->brand_id, 'user_id' => $delivery->user_id])->first();
        $quiet = $row?->quiet_start !== null && $row?->quiet_end !== null && $row->quiet_start !== $row->quiet_end;
        if (! $row || (! $quiet && $row->digest_time === null)) {
            return null;
        }
        $timezone = DB::table('profile_preferences')->where('user_id', $delivery->user_id)->value('timezone')
            ?? DB::table('brands')->where('id', $delivery->brand_id)->value('timezone') ?? 'UTC';

        return ['start' => $quiet ? $row->quiet_start : null, 'end' => $quiet ? $row->quiet_end : null, 'digest' => $row->digest_time, 'timezone' => $timezone];
    }

    private function enabled(): bool
    {
        return (bool) config('features.E0-04.defaults.external_delivery') && config('runtime.notification_driver') !== 'fake';
    }

    private function dueAt(object $delivery, array $timing): CarbonImmutable
    {
        return app(FakeTimedDelivery::class)->dueAt(CarbonImmutable::parse($delivery->created_at, 'UTC'), $timing['timezone'], $timing['start'], $timing['end'], $timing['digest']);
    }

    /** Marks a not yet due delivery as deferred («Отложено»); returns true when it must wait. */
    private function defer(object $delivery, ?array $timing = null): bool
    {
        $timing ??= $this->timing($delivery);
        if ($timing === null) {
            return false;
        }
        $now = CarbonImmutable::now('UTC');
        $allowed = app(FakeTimedDelivery::class)->dueAt($now, $timing['timezone'], $timing['start'], $timing['end'], null);
        if ($this->dueAt($delivery, $timing)->lessThanOrEqualTo($now) && $allowed->lessThanOrEqualTo($now)) {
            return false;
        }
        DB::table('notification_deliveries')->where('id', $delivery->id)->whereIn('status', ['pending', 'failed'])->update(['status' => 'deferred', 'updated_at' => now()]);

        return true;
    }

    /** @param list<string> $ids deliveries of one user, brand and channel */
    private function transmit(array $ids): void
    {
        $claimed = [];
        foreach ($ids as $id) {
            if (DB::table('notification_deliveries')->where('id', $id)->whereIn('status', ['pending', 'failed', 'deferred'])->whereIn('channel', ['email', 'telegram'])
                ->update(['status' => 'sending', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()])) {
                $claimed[] = $id;
            }
        }
        $titles = [];
        $valid = [];
        $first = null;
        $settings = null;
        foreach ($claimed as $id) {
            try {
                $delivery = DB::table('notification_deliveries')->where('id', $id)->first();
                $setting = $this->validate($delivery);
                $titles[] = EventCatalog::TITLES[$setting['type']];
                $valid[] = $id;
                $first ??= $delivery;
                $settings ??= $setting['settings'];
            } catch (Throwable) {
                DB::table('notification_deliveries')->where('id', $id)->where('status', 'sending')->update(['status' => 'failed', 'last_error' => 'delivery_failed', 'updated_at' => now()]);
            }
        }
        if ($valid === []) {
            return;
        }
        $transmissionStarted = false;
        try {
            $title = count($titles) === 1 ? $titles[0] : 'Сводка уведомлений: '.count($titles);
            if ($first->channel === 'email') {
                if (in_array(config('mail.default'), ['log', 'array'], true)) {
                    throw new RuntimeException('Mail transport is not configured.');
                }
                $user = User::query()->findOrFail($first->user_id);
                $pending = Mail::to($user->email);
                $transmissionStarted = true;
                $receipt = $pending->send(new NotificationMail($title, count($titles) > 1 ? $titles : []));
                if (! $receipt instanceof SentMessage) {
                    throw new RuntimeException('Mail delivery not confirmed.');
                }
            } else {
                $token = config('features.E0-04.defaults.telegram_bot_token');
                if (! is_string($token) || $token === '' || ! $settings->destination) {
                    throw new RuntimeException('Telegram is not configured.');
                }
                $chatId = Crypt::decryptString($settings->destination);
                $text = count($titles) === 1 ? $title : $title."\n".implode("\n", array_map(fn (string $line): string => '— '.$line, $titles));
                $transmissionStarted = true;
                $response = Http::asForm()->timeout(5)->post('https://api.telegram.org/bot'.$token.'/sendMessage', ['chat_id' => $chatId, 'text' => $text])->throw();
                if ($response->json('ok') !== true) {
                    throw new RuntimeException('Telegram delivery not confirmed.');
                }
            }
            DB::table('notification_deliveries')->whereIn('id', $valid)->where('status', 'sending')->update(['status' => 'sent', 'sent_at' => now(), 'last_error' => null, 'updated_at' => now()]);
        } catch (Throwable $e) {
            // Do not persist exception messages: URLs and transport errors may contain credentials.
            DB::table('notification_deliveries')->whereIn('id', $valid)->where('status', 'sending')->update(['status' => $transmissionStarted ? 'uncertain' : 'failed', 'last_error' => $transmissionStarted ? 'delivery_uncertain' : 'delivery_failed', 'updated_at' => now()]);
        }
    }

    /** @return array{type: string, settings: object} */
    private function validate(object $delivery): array
    {
        $member = Membership::withoutGlobalScopes()->where('organization_id', $delivery->organization_id)->where('user_id', $delivery->user_id)->where('status', 'active')->first();
        if (! $member || ! $member->permitsBrand($delivery->brand_id)) {
            throw new RuntimeException('Recipient access revoked.');
        }
        $outbox = DB::table('outbox_events')->where('id', $delivery->event_id)->where('organization_id', $delivery->organization_id)->first();
        if (! $outbox) {
            throw new RuntimeException('Event not found.');
        }
        $event = new DomainEvent(json_decode($outbox->envelope, true, 512, JSON_THROW_ON_ERROR));
        if ($event->envelope['type'] === 'seller.contact.reminder') {
            throw new RuntimeException('Contact reminders are restricted to fake delivery.');
        }
        if (! app(RecipientPolicy::class)->permits($member, $delivery->brand_id, $event->envelope['type'])) {
            throw new RuntimeException('Recipient policy changed.');
        }
        $settings = DB::table('notification_settings')->where(['organization_id' => $delivery->organization_id, 'brand_id' => $delivery->brand_id, 'user_id' => $delivery->user_id, 'event_key' => $event->envelope['type'], 'channel' => $delivery->channel])->first();
        if (! $settings || ! $settings->enabled) {
            throw new RuntimeException('Notification disabled.');
        }
        if (! app(NotificationRouting::class)->allowsImmediate($event, $member, $delivery->channel)) {
            throw new RuntimeException('Brand notification policy changed.');
        }

        return ['type' => $event->envelope['type'], 'settings' => $settings];
    }
}
