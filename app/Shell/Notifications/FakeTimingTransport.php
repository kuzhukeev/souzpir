<?php

namespace App\Shell\Notifications;

class FakeTimingTransport
{
    public function send(string $organizationId, string $brandId, string $userId, string $channel, array $titles): void
    {
        // Fake transport only: no network calls.
    }
}
