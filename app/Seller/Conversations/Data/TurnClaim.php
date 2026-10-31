<?php

namespace App\Seller\Conversations\Data;

final readonly class TurnClaim
{
    public function __construct(public string $status, public string $turnId, public string $conversationId, public string $organizationId, public string $brandId, public int $baseVersion, private ?string $token = null, public ?array $outcome = null) {}

    public function token(): ?string
    {
        return $this->token;
    }

    public function __debugInfo(): array
    {
        return ['status' => $this->status, 'turnId' => $this->turnId, 'conversationId' => $this->conversationId, 'baseVersion' => $this->baseVersion, 'token' => '[REDACTED]'];
    }
}
