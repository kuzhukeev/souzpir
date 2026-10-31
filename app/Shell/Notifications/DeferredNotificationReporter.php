<?php

namespace App\Shell\Notifications;

use Illuminate\Support\Facades\Log;

final class DeferredNotificationReporter
{
    /** Deliberately log neither the event nor the exception/trace. */
    public function report(NotificationDeferred $deferred): void
    {
        Log::notice('Notification processing deferred.', ['reason' => $deferred->reason]);
    }
}
