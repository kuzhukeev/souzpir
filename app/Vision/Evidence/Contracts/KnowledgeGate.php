<?php

namespace App\Vision\Evidence\Contracts;

use App\Tenancy\TenantContext;

interface KnowledgeGate
{
    /** Verify the published release, tenant, consent, revocation and data classes. Throw on any doubt. */
    public function assertAllowed(TenantContext $tenant, string $measurementId, #[\SensitiveParameter] array $frozenProfile): void;
}
