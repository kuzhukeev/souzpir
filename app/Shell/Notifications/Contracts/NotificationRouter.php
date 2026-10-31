<?php

namespace App\Shell\Notifications\Contracts;

use App\Models\Membership;
use App\Shell\Notifications\DomainEvent;

interface NotificationRouter
{
    /** Called in the outbox transaction after base access and personal preferences. */
    public function route(DomainEvent $event, Membership $member, string $channel): string;

    /** Recheck policy immediately before starting an external immediate delivery. */
    public function allowsImmediate(DomainEvent $event, Membership $member, string $channel): bool;
}
