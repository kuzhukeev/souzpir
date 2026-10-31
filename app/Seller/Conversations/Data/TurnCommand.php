<?php

namespace App\Seller\Conversations\Data;

final readonly class TurnCommand
{
    public function __construct(public string $conversationId, public string $inputMessageId, public string $text, public ?string $externalEventId, public string $idempotencyKey, public ?int $expectedVersion = null) {}
}
