<?php

namespace App\Vision\Usage;

use App\Shell\Plans\Contracts\UsageContributor;
use App\Shell\Plans\Contracts\UsageQuery;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final class MeasurementUsage implements UsageContributor
{
    public function read(TenantContext $context, UsageQuery $query): array
    {
        $wanted = array_intersect($query->metrics, ['vision.measurements', 'ai.calls']);
        if (! $wanted) {
            return [];
        }
        $organization = $context->requireOrganizationId();
        $brand = $context->brandId;
        abort_unless($brand && DB::table('brands')->where('id', $brand)->where('organization_id', $organization)->where('status', 'active')->exists(), 404);
        $scope = fn (string $table) => DB::table($table)->where('organization_id', $organization)->where('brand_id', $brand);
        $from = $query->from->format('Y-m-d H:i:sP');
        $to = $query->to->format('Y-m-d H:i:sP');
        $out = [];
        if (in_array('vision.measurements', $wanted, true)) {
            $out['vision.measurements'] = ['value' => $scope('measurements')->where('status', 'completed')->where('finished_at', '>=', $from)->where('finished_at', '<', $to)->count(), 'unit' => 'measurements', 'status' => 'ready'];
        }
        if (in_array('ai.calls', $wanted, true)) {
            $out['ai.calls'] = ['value' => $scope('ai_calls')->where('status', 'completed')->where('created_at', '>=', $from)->where('created_at', '<', $to)->count(), 'unit' => 'calls', 'status' => 'ready'];
        }

        return $out;
    }
}
