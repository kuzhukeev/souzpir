<?php

namespace App\Shell\Data\Contracts;

use App\Tenancy\TenantContext;

interface ExportContributor
{
    public function module(): string;

    /** @return iterable<ExportRecord> Only records belonging to the supplied tenant and period. */
    public function stream(TenantContext $tenant, ExportQuery $query): iterable;
}
