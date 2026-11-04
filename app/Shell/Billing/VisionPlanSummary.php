<?php

namespace App\Shell\Billing;

use App\Shell\Plans\Contracts\PlanLimits;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final class VisionPlanSummary
{
    public function read(TenantContext $tenant): array
    {
        $plan = DB::table('organization_plan as assignment')->join('plans as plan', 'plan.id', '=', 'assignment.plan_id')
            ->where('assignment.organization_id', $tenant->organizationId)->where('assignment.starts_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('assignment.ends_at')->orWhere('assignment.ends_at', '>', now()))
            ->first(['plan.name']);
        $limits = app(PlanLimits::class);

        return ['name' => $plan?->name, 'questions' => $limits->get($tenant, 'vision.questions')->value, 'runs_per_week' => $limits->get($tenant, 'vision.runs_per_week')->value];
    }
}
