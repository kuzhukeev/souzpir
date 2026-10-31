<?php

namespace App\Shell\Integrations\Webhooks;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DrainWebhooks extends Command
{
    protected $signature = 'integrations:webhooks-drain';

    protected $description = 'Consume committed integration events and process pending webhook deliveries';

    public function handle(WebhookOutbox $consumer, WebhookDelivery $delivery): int
    {
        if (! WebhookDelivery::available()) {
            $this->error('Отправка вебхуков пока недоступна: снимок подписок не подтверждён.');

            return self::FAILURE;
        }
        if (DB::transactionLevel() !== 0) {
            $this->error('Webhook scanning requires no active transaction.');

            return self::FAILURE;
        }
        try {
            $events = DB::table('outbox_events')->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('consumed_events')->whereColumn('consumed_events.event_id', 'outbox_events.id')
                    ->where('consumer', 'integrations.webhooks.v1');
            })->orderBy('created_at')->orderBy('id')->limit(100)->pluck('id');
            foreach ($events as $id) {
                $consumer->consume($id);
            }
            if (config('features.E1-10.defaults.webhook_delivery', false)) {
                foreach (DB::table('webhook_deliveries')->where('status', 'pending')->orderBy('created_at')->limit(100)->pluck('id') as $id) {
                    $delivery->send($id);
                }
            }
        } catch (Throwable) {
            $this->error('Webhook batch could not be processed.');

            return self::FAILURE;
        }
        $this->info('Webhook batch processed.');

        return self::SUCCESS;
    }
}
