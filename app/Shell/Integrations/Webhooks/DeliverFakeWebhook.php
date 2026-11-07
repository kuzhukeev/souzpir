<?php

namespace App\Shell\Integrations\Webhooks;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DeliverFakeWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $deliveryId) {}

    public function handle(FakeWebhookTransport $transport): void
    {
        $delivery = DB::table('webhook_deliveries')->where('id', $this->deliveryId)->where('status', 'pending')->first();
        if (! $delivery) {
            return;
        }
        $hook = DB::table('webhooks')->where('id', $delivery->webhook_id)->where('organization_id', $delivery->organization_id)->where('brand_id', $delivery->brand_id)->first();
        if (! $hook) {
            DB::table('webhook_deliveries')->where('id', $this->deliveryId)->update(['status' => 'failed', 'updated_at' => now()]);

            return;
        }
        $body = $delivery->payload;
        try {
            $url = str_starts_with($hook->url, 'https://') ? $hook->url : Crypt::decryptString($hook->url);
            $signature = hash_hmac('sha256', $body, Crypt::decryptString($hook->secret));
        } catch (Throwable) {
            DB::table('webhook_deliveries')->where('id', $delivery->id)->where('status', 'pending')->update(['status' => 'failed', 'updated_at' => now()]);

            return;
        }
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $status = null;
            try {
                $status = $transport->send($url, $body, ['Content-Type' => 'application/json', 'X-Souzpir-Signature' => 'sha256='.$signature, 'X-Souzpir-Delivery' => $delivery->id]);
            } catch (Throwable) {
                // Transport failures contain no useful user-facing data.
            }
            $successful = $status !== null && $status >= 200 && $status < 300;
            DB::table('webhook_deliveries')->where('id', $delivery->id)->where('status', 'pending')->update(['status' => $successful ? 'simulated' : ($attempt === 5 ? 'failed' : 'pending'), 'attempts' => $attempt, 'status_code' => $status, 'updated_at' => now()]);
            if ($successful) {
                return;
            }
        }
    }
}
