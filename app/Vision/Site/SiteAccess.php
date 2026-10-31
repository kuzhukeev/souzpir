<?php

namespace App\Vision\Site;

use App\Shell\Organizations\ModuleEntitlements;
use App\Tenancy\TenantContext;
use App\Vision\KnowledgeGrants\GrantAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

final class SiteAccess
{
    public function __construct(private GrantAccess $access, private ModuleEntitlements $modules) {}

    public function lock(TenantContext $tenant, bool $write, string $ability): void
    {
        try {
            // GrantAccess holds the organization, brand and membership rows for this transaction.
            $this->access->lock($tenant, $write, $ability);
        } catch (LogicException) {
            abort(403);
        }

        // The brand parent lock serializes the normal insert path when this row is absent.
        $module = DB::table('brand_modules')->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->where('module', 'vision');
        ($write ? $module->lockForUpdate() : $module->sharedLock())->first();

        // Hold existing assignments and limits until the surrounding operation completes.
        // ModuleEntitlements remains the authority for interpreting an unconfigured limit.
        $assignments = DB::table('organization_plan')->where('organization_id', $tenant->organizationId)
            ->sharedLock()->get(['plan_id']);
        if ($assignments->isNotEmpty()) {
            $plans = $assignments->pluck('plan_id')->unique()->sort()->values()->all();
            // Block FK-backed insertion of an absent limit while evaluating this plan.
            DB::table('plans')->whereIn('id', $plans)->orderBy('id')->lockForUpdate()->get(['id']);
            DB::table('plan_limits')->whereIn('plan_id', $plans)
                ->where('key', 'modules.vision')->sharedLock()->get(['id']);
        }
        abort_unless($this->modules->enabled($tenant, 'vision'), 404);
    }

    public function can(string $ability): bool
    {
        return Gate::allows($ability);
    }
}
