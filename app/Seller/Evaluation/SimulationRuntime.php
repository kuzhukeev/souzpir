<?php

namespace App\Seller\Evaluation;

use App\Tenancy\TenantContext;

/** Bridge for the protected Seller runtime once its open/turn API is available. */
interface SimulationRuntime
{
    /** @return array{conversation_id: string, agent: string} */
    public function open(TenantContext $context, string $key): array;

    /** @return array{agent: string} */
    public function turn(TenantContext $context, string $conversationId, string $client, string $key): array;
}
