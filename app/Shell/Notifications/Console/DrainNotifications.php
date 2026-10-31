<?php

namespace App\Shell\Notifications\Console;

use App\Shell\Notifications\ExternalDelivery;
use App\Shell\Notifications\NotificationProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class DrainNotifications extends Command
{
    protected $signature = 'notifications:drain {--retry-failed : Explicitly retry deliveries marked failed}';

    protected $description = 'Process committed notification outbox and enabled external deliveries';

    public function handle(NotificationProcessor $processor, ExternalDelivery $delivery): int
    {
        $events = DB::table('outbox_events')->whereNull('processed_at')->orderBy('created_at')->limit(100)->pluck('id');
        foreach ($events as $eventId) {
            $processor->process($eventId);
        }
        if (! config('features.E0-04.defaults.external_delivery')) {
            $this->info('Outbox processed; external delivery is disabled.');

            return self::SUCCESS;
        }
        $statuses = $this->option('retry-failed') ? ['pending', 'failed'] : ['pending'];
        $ids = DB::table('notification_deliveries')->whereIn('status', $statuses)->whereIn('channel', ['email', 'telegram'])->orderBy('created_at')->limit(100)->pluck('id');
        foreach ($ids as $id) {
            $delivery->send($id);
        }
        $this->info('Notification batch processed.');

        return self::SUCCESS;
    }
}
