<?php

namespace App\Shell\Notifications\Console;

use App\Shell\Notifications\DeferredNotificationReporter;
use App\Shell\Notifications\ExternalDelivery;
use App\Shell\Notifications\NotificationDeferred;
use App\Shell\Notifications\NotificationProcessor;
use App\Shell\Notifications\OutboxCursor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DrainNotifications extends Command
{
    protected $signature = 'notifications:drain {--retry-failed : Explicitly retry deliveries marked failed}';

    protected $description = 'Process committed notification outbox and enabled external deliveries';

    public function handle(NotificationProcessor $processor, ExternalDelivery $delivery, OutboxCursor $cursor, DeferredNotificationReporter $reporter): int
    {
        if (DB::transactionLevel() !== 0) {
            $this->error('Notification scanning requires no active transaction.');

            return self::FAILURE;
        }
        $errors = 0;
        try {
            $events = $cursor->reserve();
        } catch (Throwable) {
            $events = [];
            $errors++;
            Log::error('Notification outbox page could not be reserved.', ['code' => 'outbox_cursor_failed']);
        }
        foreach ($events as $eventId) {
            try {
                $processor->process($eventId);
            } catch (NotificationDeferred $deferred) {
                try {
                    $reporter->report($deferred);
                } catch (Throwable) {
                    // A broken logger must not stop other events or be reported as success.
                    $errors++;
                }
            } catch (Throwable) {
                $errors++;
                Log::error('Notification event processing failed.', ['code' => 'outbox_processing_failed']);
            }
        }
        if (config('features.E0-04.defaults.external_delivery')) {
            try {
                $statuses = $this->option('retry-failed') ? ['pending', 'failed'] : ['pending'];
                $ids = DB::table('notification_deliveries')->whereIn('status', $statuses)->whereIn('channel', ['email', 'telegram'])->orderBy('created_at')->orderBy('id')->limit(100)->pluck('id');
                foreach ($ids as $id) {
                    try {
                        $delivery->send($id);
                    } catch (Throwable) {
                        $errors++;
                        Log::error('Notification delivery processing failed.', ['code' => 'delivery_processing_failed']);
                    }
                }
            } catch (Throwable) {
                $errors++;
                Log::error('Notification delivery page could not be loaded.', ['code' => 'delivery_page_failed']);
            }
        }
        if ($errors > 0) {
            $this->error('Some notifications could not be processed.');

            return self::FAILURE;
        }
        $this->info('Notification batch processed.');

        return self::SUCCESS;
    }
}
