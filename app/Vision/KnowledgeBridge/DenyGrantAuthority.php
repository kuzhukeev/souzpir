<?php

namespace App\Vision\KnowledgeBridge;

use App\Tenancy\TenantContext;
use LogicException;

/** No source of durable revocation yet: deny every grant until integrated. */
final class DenyGrantAuthority implements GrantAuthority
{
    public function assertActive(TenantContext $tenant, string $grantId, string $releaseId, string $policyChecksum): void
    {
        throw new LogicException('Knowledge grant authority is not configured.');
    }
}
