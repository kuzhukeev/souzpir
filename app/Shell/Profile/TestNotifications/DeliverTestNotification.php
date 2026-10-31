<?php

namespace App\Shell\Profile\TestNotifications;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DeliverTestNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $id)
    {
        $this->onQueue(config('runtime.queue_namespace').':notifications');
    }

    public function handle(TestNotifications $notifications): void
    {
        $notifications->deliver($this->id);
    }
}
