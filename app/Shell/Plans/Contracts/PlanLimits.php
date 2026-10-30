<?php

namespace App\Shell\Plans\Contracts;

use App\Tenancy\TenantContext;

interface PlanLimits
{
    public function get(TenantContext $context, string $key): LimitValue;
}
