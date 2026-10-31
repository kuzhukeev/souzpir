<?php

namespace App\Shell\Notifications\Contracts;

use App\Shell\Notifications\DomainEvent;

interface NotificationDispatcher
{
    public function enqueue(DomainEvent $event): void;
}
