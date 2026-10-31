<?php

namespace App\Shell\Support;

use App\Shell\Support\Contracts\ProviderCooldowns;
use App\Tenancy\TenantContext;

final class UnavailableProviderCooldowns implements ProviderCooldowns
{
    public function snapshot(TenantContext $context): array
    {
        return ['available' => false, 'checked_at' => null, 'cooldowns' => []];
    }
}
