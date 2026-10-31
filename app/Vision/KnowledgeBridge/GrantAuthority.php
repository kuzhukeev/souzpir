<?php

namespace App\Vision\KnowledgeBridge;

use App\Tenancy\TenantContext;

/** Implementations must lock an active, immutable tenant/release/policy grant until the caller commits. */
interface GrantAuthority
{
    public function assertActive(TenantContext $tenant, string $grantId, string $releaseId, string $policyChecksum): void;
}
