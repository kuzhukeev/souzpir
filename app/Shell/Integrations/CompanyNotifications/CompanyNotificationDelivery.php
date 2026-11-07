<?php

namespace App\Shell\Integrations\CompanyNotifications;

use App\Models\Brand;
use App\Shell\Notifications\NotificationMail;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

final class CompanyNotificationDelivery
{
    /** Behind an explicit opt-in flag; off by default until the owner connects live receivers. */
    public static function available(): bool
    {
        return config('features.E1-10.defaults.company_notification_delivery', false) === true;
    }

    public function send(string $id): void
    {
        if (! self::available()) {
            return;
        }
        $claimed = DB::table('company_notification_deliveries')->where('id', $id)->where('status', 'pending')
            ->update(['status' => 'sending', 'updated_at' => now()]);
        if (! $claimed) {
            return;
        }
        $delivery = DB::table('company_notification_deliveries')->where('id', $id)->first();
        $brand = Brand::withoutGlobalScopes()->where('id', $delivery->brand_id)->where('organization_id', $delivery->organization_id)->where('status', 'active')->first();
        $info = CompanyNotificationDispatcher::EVENTS[$delivery->event_key] ?? null;
        if (! $brand || ! $info) {
            DB::table('company_notification_deliveries')->where('id', $id)->where('status', 'sending')->update(['status' => 'failed', 'updated_at' => now()]);

            return;
        }
        try {
            $destination = Crypt::decryptString($delivery->destination);
            $settings = $brand->settings['company_notifications'] ?? [];
            $enabled = ($settings['matrix'][$info[0]][$delivery->channel] ?? false) === true;
            $current = $delivery->channel === 'email'
                ? in_array($destination, $settings['report_emails'] ?? [], true)
                : (is_string($settings['telegram_chat'] ?? null) && Crypt::decryptString($settings['telegram_chat']) === $destination);
            if (! $enabled || ! $current) {
                DB::table('company_notification_deliveries')->where('id', $id)->where('status', 'sending')->update(['status' => 'cancelled', 'updated_at' => now()]);

                return;
            }
            if ($delivery->channel === 'email') {
                if (in_array(config('mail.default'), ['log', 'array'], true)) {
                    throw new RuntimeException('Mail transport is not configured.');
                }
                $receipt = Mail::to($destination)->send(new NotificationMail($info[1]));
                if (! $receipt instanceof SentMessage) {
                    throw new RuntimeException('Mail delivery not confirmed.');
                }
            } else {
                $token = config('features.E0-04.defaults.telegram_bot_token');
                if (! is_string($token) || $token === '') {
                    throw new RuntimeException('Telegram is not configured.');
                }
                $response = Http::asForm()->timeout(5)->post('https://api.telegram.org/bot'.$token.'/sendMessage', ['chat_id' => $destination, 'text' => $info[1]])->throw();
                if ($response->json('ok') !== true) {
                    throw new RuntimeException('Telegram delivery not confirmed.');
                }
            }
            DB::table('company_notification_deliveries')->where('id', $id)->where('status', 'sending')->update(['status' => 'sent', 'updated_at' => now()]);
        } catch (Throwable) {
            // Do not persist exception messages: transport errors may contain credentials.
            DB::table('company_notification_deliveries')->where('id', $id)->where('status', 'sending')->update(['status' => 'failed', 'updated_at' => now()]);
        }
    }
}
