<?php

namespace App\Shell\Organizations;

use App\Shell\Plans\Contracts\PlanLimits;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** The source of truth for HTTP, jobs and frontend; requested onboarding modules do not grant access. */
final class ModuleEntitlements
{
    public function __construct(private readonly PlanLimits $limits) {}

    public function allowedByPlan(TenantContext $context, string $module): bool
    {
        $this->validateModule($module);
        $limit = $this->limits->get($context, 'modules.'.$module);

        return ! $limit->configured || ($limit->value !== null && preg_match('/[1-9]/', $limit->value) === 1);
    }

    public function enabled(TenantContext $context, string $module): bool
    {
        $this->validateModule($module);
        if (! $context->organizationId || ! $context->brandId) {
            return false;
        }
        $exists = DB::table('brand_modules as module')
            ->join('brands as brand', 'brand.id', '=', 'module.brand_id')
            ->join('organizations as organization', 'organization.id', '=', 'brand.organization_id')
            ->where('module.organization_id', $context->organizationId)->where('module.brand_id', $context->brandId)
            ->where('brand.organization_id', $context->organizationId)->where('brand.status', 'active')->where('organization.status', 'active')
            ->where('module.module', $module)->where('module.enabled', true)->exists();

        return $exists && $this->allowedByPlan($context, $module);
    }

    private function validateModule(string $module): void
    {
        if (! in_array($module, ['seller', 'vision'], true)) {
            throw new InvalidArgumentException('Unknown module.');
        }
    }
}
