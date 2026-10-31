<?php

namespace App\Shell\Auth\PasswordDelivery;

use LogicException;

final readonly class DeliveryClaim
{
    public function __construct(public string $id, #[\SensitiveParameter] private string $token) {}

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
        throw new LogicException('Delivery claims cannot be serialized.');
    }
}
