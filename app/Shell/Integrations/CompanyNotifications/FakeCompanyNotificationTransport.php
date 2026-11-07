<?php

namespace App\Shell\Integrations\CompanyNotifications;

class FakeCompanyNotificationTransport
{
    public function send(string $channel, string $destination, string $title): void
    {
        // In-memory fake: external delivery is deliberately disabled.
    }
}
