<?php

namespace App\Seller\Dialogs;

final class FakeManagerMessageTransport implements ManagerMessageTransport
{
    public function send(string $organizationId, string $brandId, string $conversationId, string $messageId): void
    {
        // Fake sink: no network calls.
    }
}
