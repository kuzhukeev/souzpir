<?php

namespace App\Shell\Notifications;

use InvalidArgumentException;
use RuntimeException;

/** A known, temporary condition; never include event contents in this exception. */
final class NotificationDeferred extends RuntimeException
{
    public function __construct(#[\SensitiveParameter] public readonly string $reason)
    {
        if ($reason !== 'digest_disabled') {
            throw new InvalidArgumentException('Invalid notification deferral reason.');
        }
        parent::__construct('Notification processing is temporarily deferred.');
    }
}
