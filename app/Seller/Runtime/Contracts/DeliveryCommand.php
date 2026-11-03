<?php

namespace App\Seller\Runtime\Contracts;

use App\Tenancy\TenantContext;

final readonly class DeliveryCommand
{
    public function __construct(
        public TenantContext $tenant,
        public string $conversationId,
        public string $messageId,
        public string $channel,
        public string $idempotencyKey,
    ) {}
}
