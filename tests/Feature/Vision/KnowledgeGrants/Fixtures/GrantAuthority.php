<?php

namespace App\Vision\KnowledgeBridge;

use App\Tenancy\TenantContext;

// Test-only substitute until the separately owned bridge is integrated.
interface GrantAuthority
{
    public function assertActive(TenantContext $tenant, string $grantId, string $releaseId, string $policyChecksum): void;
}
