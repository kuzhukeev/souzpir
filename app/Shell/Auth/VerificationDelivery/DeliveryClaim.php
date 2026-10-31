<?php

namespace App\Shell\Auth\VerificationDelivery;

use LogicException;

/** Ephemeral capability: do not queue, persist, or return to a client. */
final readonly class DeliveryClaim
{
    public function __construct(public string $id, private string $token) {}

    public function tokenHash(): string
    {
        return hash('sha256', $this->token);
    }

    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'token' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('A delivery claim cannot be serialized.');
    }
}
