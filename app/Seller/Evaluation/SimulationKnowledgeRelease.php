<?php

namespace App\Seller\Evaluation;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final class SimulationKnowledgeRelease
{
    public function id(TenantContext $context): ?string
    {
        return DB::table('brands as b')->join('knowledge_releases as r', function ($join): void {
            $join->on('r.id', '=', 'b.active_knowledge_release_id')->on('r.brand_id', '=', 'b.id')->on('r.organization_id', '=', 'b.organization_id');
        })->where('b.organization_id', $context->requireOrganizationId())->where('b.id', $context->brandId)
            ->where('r.status', 'published')->value('r.id');
    }
}
