<?php

namespace App\Shell\Plans;

use App\Shell\Plans\Contracts\LimitValue;
use App\Shell\Plans\Contracts\PlanLimits;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class DatabasePlanLimits implements PlanLimits
{
    public function get(TenantContext $context, string $key): LimitValue
    {
        $organizationId = $context->requireOrganizationId();
        if (! preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/D', $key)) {
            throw new InvalidArgumentException('Invalid limit key.');
        }
        $now = now()->toIso8601String();
        $row = DB::table('organization_plan as assigned')
            ->join('plan_limits as limits', 'limits.plan_id', '=', 'assigned.plan_id')
            ->where('assigned.organization_id', $organizationId)
            ->where('assigned.starts_at', '<=', $now)
            ->where(fn ($query) => $query->whereNull('assigned.ends_at')->orWhere('assigned.ends_at', '>', $now))
            ->where('limits.key', $key)->select('limits.value', 'limits.unit')->first();

        return new LimitValue($row?->value === null ? null : (string) $row->value, $row?->unit ?? '', $row?->value !== null);
    }
}
