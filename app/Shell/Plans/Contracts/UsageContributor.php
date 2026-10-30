<?php

namespace App\Shell\Plans\Contracts;

use App\Tenancy\TenantContext;

interface UsageContributor
{
    /** @return array<string,array{value: int|float|string|null,unit: string,status: string}> */
    public function read(TenantContext $context, UsageQuery $query): array;
}
