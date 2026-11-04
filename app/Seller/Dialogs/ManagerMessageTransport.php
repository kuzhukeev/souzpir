<?php

namespace App\Seller\Dialogs;

interface ManagerMessageTransport
{
    public function send(string $organizationId, string $brandId, string $conversationId, string $messageId): void;
}
