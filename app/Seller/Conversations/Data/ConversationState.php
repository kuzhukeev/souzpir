<?php

namespace App\Seller\Conversations\Data;

final readonly class ConversationState
{
    public function __construct(public string $id, public string $organizationId, public string $brandId, public string $mode, public string $stage, public ?string $outcome, public string $clientType, public array $state, public int $version, public ?string $channelAccountId, public ?string $contactId) {}
}
