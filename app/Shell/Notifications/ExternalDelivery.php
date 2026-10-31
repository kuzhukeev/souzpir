<?php

namespace App\Shell\Notifications;

use App\Models\Membership;
use App\Models\User;
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
        if (! config('features.E0-04.defaults.external_delivery')) {
            return;
        }
        $claimed = DB::table('notification_deliveries')->where('id', $id)->whereIn('status', ['pending', 'failed'])
            ->whereIn('channel', ['email', 'telegram'])->update(['status' => 'sending', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        if (! $claimed) {
            return;
        }
        try {
            $delivery = DB::table('notification_deliveries')->where('id', $id)->first();
            $member = Membership::withoutGlobalScopes()->where('organization_id', $delivery->organization_id)->where('user_id', $delivery->user_id)->where('status', 'active')->first();
            if (! $member || ! $member->permitsBrand($delivery->brand_id)) {
                throw new RuntimeException('Recipient access revoked.');
            }
            $outbox = DB::table('outbox_events')->where('id', $delivery->event_id)->where('organization_id', $delivery->organization_id)->first();
            if (! $outbox) {
                throw new RuntimeException('Event not found.');
            }
            $event = new DomainEvent(json_decode($outbox->envelope, true, 512, JSON_THROW_ON_ERROR));
            if (! app(RecipientPolicy::class)->permits($member, $delivery->brand_id, $event->envelope['type'])) {
                throw new RuntimeException('Recipient policy changed.');
            }
            $settings = DB::table('notification_settings')->where(['organization_id' => $delivery->organization_id, 'brand_id' => $delivery->brand_id, 'user_id' => $delivery->user_id, 'event_key' => $event->envelope['type'], 'channel' => $delivery->channel])->first();
            if (! $settings || ! $settings->enabled) {
                throw new RuntimeException('Notification disabled.');
            }
            $title = EventCatalog::TITLES[$event->envelope['type']];
            if ($delivery->channel === 'email') {
                if (in_array(config('mail.default'), ['log', 'array'], true)) {
                    throw new RuntimeException('Mail transport is not configured.');
                }
                $user = User::query()->findOrFail($delivery->user_id);
                Mail::to($user->email)->send(new NotificationMail($title));
            } else {
                $token = config('features.E0-04.defaults.telegram_bot_token');
                if (! is_string($token) || $token === '' || ! $settings->destination) {
                    throw new RuntimeException('Telegram is not configured.');
                }
                $chatId = Crypt::decryptString($settings->destination);
                Http::asForm()->timeout(5)->post('https://api.telegram.org/bot'.$token.'/sendMessage', ['chat_id' => $chatId, 'text' => $title])->throw();
            }
            DB::table('notification_deliveries')->where('id', $id)->where('status', 'sending')->update(['status' => 'sent', 'sent_at' => now(), 'last_error' => null, 'updated_at' => now()]);
        } catch (Throwable $e) {
            // Do not persist exception messages: URLs and transport errors may contain credentials.
            DB::table('notification_deliveries')->where('id', $id)->where('status', 'sending')->update(['status' => 'failed', 'last_error' => 'delivery_failed', 'updated_at' => now()]);
        }
    }
}
