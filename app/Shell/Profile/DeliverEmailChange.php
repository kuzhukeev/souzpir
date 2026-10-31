<?php

namespace App\Shell\Profile;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DeliverEmailChange implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $intentId) {}

    public function handle(EmailChanges $changes): void
    {
        $changes->deliver($this->intentId);
    }
}
