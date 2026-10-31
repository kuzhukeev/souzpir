<?php

namespace App\Vision\Site;

use App\Tenancy\TenantContext;
use App\Vision\KnowledgeGrants\GrantAccess;
use Illuminate\Support\Facades\Gate;
use LogicException;

final class SiteAccess
{
    public function __construct(private GrantAccess $access) {}

    public function lock(TenantContext $tenant, bool $write, string $ability): void
    {
        try {
            $this->access->lock($tenant, $write, $ability);
        } catch (LogicException) {
            abort(403);
        }
    }

    public function can(string $ability): bool
    {
        return Gate::allows($ability);
    }
}
