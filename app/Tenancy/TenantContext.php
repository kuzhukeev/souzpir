<?php

namespace App\Tenancy;

use Illuminate\Support\Str;
use LogicException;

/** An empty scoped instance means unresolved context, never wildcard access. */
final readonly class TenantContext
{
    public function __construct(
        public ?string $organizationId = null,
        public ?string $brandId = null,
        public ?string $actorId = null,
        public ?string $membershipId = null,
        public string $source = 'session',
    ) {
        foreach ([$organizationId, $brandId, $actorId, $membershipId] as $id) {
            if ($id !== null && ! Str::isUlid($id)) {
                throw new LogicException('Tenant identifiers must be ULIDs.');
            }
        }
        if (! in_array($source, ['session', 'token', 'job', 'public'], true) || ($organizationId === null && ($brandId !== null || $actorId !== null || $membershipId !== null))) {
            throw new LogicException('Invalid tenant context.');
        }
    }

    public function requireOrganizationId(): string
    {
        return $this->organizationId ?? throw new LogicException('Organization context is required.');
    }
}
