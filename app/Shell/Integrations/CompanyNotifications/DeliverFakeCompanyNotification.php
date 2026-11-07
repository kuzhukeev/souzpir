<?php

namespace App\Shell\Integrations\CompanyNotifications;

use App\Models\Brand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DeliverFakeCompanyNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $deliveryId) {}

    public function handle(FakeCompanyNotificationTransport $transport): void
    {
        $delivery = DB::table('company_notification_deliveries')->where('id', $this->deliveryId)->where('status', 'pending')->first();
        if (! $delivery) {
            return;
        }
        $brand = Brand::withoutGlobalScopes()->where('id', $delivery->brand_id)->where('organization_id', $delivery->organization_id)->where('status', 'active')->first();
        $info = CompanyNotificationDispatcher::EVENTS[$delivery->event_key] ?? null;
        if (! $brand || ! $info) {
            DB::table('company_notification_deliveries')->where('id', $delivery->id)->update(['status' => 'failed', 'updated_at' => now()]);

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
                DB::table('company_notification_deliveries')->where('id', $delivery->id)->where('status', 'pending')->update(['status' => 'cancelled', 'updated_at' => now()]);

                return;
            }
            $transport->send($delivery->channel, $destination, $info[1]);
            DB::table('company_notification_deliveries')->where('id', $delivery->id)->where('status', 'pending')->update(['status' => 'simulated', 'updated_at' => now()]);
        } catch (Throwable) {
            DB::table('company_notification_deliveries')->where('id', $delivery->id)->where('status', 'pending')->update(['status' => 'failed', 'updated_at' => now()]);
        }
    }
}
