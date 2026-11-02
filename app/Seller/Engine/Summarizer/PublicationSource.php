<?php

namespace App\Seller\Engine\Summarizer;

use App\Tenancy\TenantContext;

/** Supplies tenant-scoped conversation history and published summary input. */
interface PublicationSource
{
    public function load(TenantContext $tenant, string $conversationId): array;
}
