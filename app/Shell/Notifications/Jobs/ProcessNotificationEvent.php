<?php

namespace App\Shell\Notifications\Jobs;

use App\Shell\Notifications\NotificationProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessNotificationEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $eventId) {}

    public function handle(NotificationProcessor $processor): void
    {
        $processor->process($this->eventId);
    }
}
