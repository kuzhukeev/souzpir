<?php

namespace App\Shell\Support\Contracts;

use App\Tenancy\TenantContext;

/** Support-local read boundary. A shared AI runtime adapter is not installed yet. */
interface ProviderCooldowns
{
    /** @return array{available: bool, checked_at: ?string, cooldowns: array<string, string>} */
    public function snapshot(TenantContext $context): array;
}
