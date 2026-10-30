<?php

namespace App\Shell\Plans\Contracts;

use App\Tenancy\TenantContext;

interface UsageReader
{
    public function read(TenantContext $context, UsageQuery $query): UsageSnapshot;
}
