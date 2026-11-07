<?php

namespace App\Seller\Conversations;

use App\Models\Membership;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Offline operator diagnostic only. Never changes or transfers an assignment. */
final class AssigneeAudit
{
    public function countInvalid(TenantContext $context, string $brandId): array
    {
        $current = app(TenantContext::class);
        if ($context->source !== 'job' || $context->actorId !== null || $context->membershipId !== null || $context->organizationId === null || $context->brandId !== null || $current->source !== 'job' || $current->actorId !== null || $current->membershipId !== null || $current->organizationId !== $context->organizationId || $current->brandId !== null) {
            throw new LogicException('An authorized operator tenant is required.');
        }
        $active = DB::table('brands')->where('id', $brandId)->where('organization_id', $context->organizationId)->whereExists(fn ($q) => $q->selectRaw('1')->from('organizations')->whereColumn('organizations.id', 'brands.organization_id')->where('organizations.status', 'active'))->exists();
        if (! $active) {
            throw new LogicException('An active operator tenant is required.');
        }
        $counts = [];
        foreach (['conversations', 'seller_tasks'] as $table) {
            $counts[$table] = ['examined' => 0, 'invalid' => 0];
            DB::table($table)->where('organization_id', $context->organizationId)->where('brand_id', $brandId)->whereNotNull('assigned_user_id')->orderBy('id')->chunkById(250, function ($rows) use (&$counts, $table, $context, $brandId): void {
                foreach ($rows as $row) {
                    $counts[$table]['examined']++;
                    $member = Membership::withoutGlobalScopes()->where('organization_id', $context->organizationId)->where('user_id', $row->assigned_user_id)->where('status', 'active')->first();
                    if (! $member || ! $member->permitsBrand($brandId) || ! AssigneeEligibility::canOperate($member)) {
                        $counts[$table]['invalid']++;
                    }
                }
            });
        }

        return $counts;
    }
}
