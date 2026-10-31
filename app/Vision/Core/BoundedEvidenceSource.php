<?php

namespace App\Vision\Core;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Atomic, tenant-bound source for both EvidenceEvaluator reads. */
final class BoundedEvidenceSource
{
    public function read(TenantContext $tenant, string $answerId): ?object
    {
        try {
            $current = app(TenantContext::class);
            if (! $tenant->organizationId || ! $tenant->brandId || ! Str::isUlid($answerId)
                || $tenant->source === 'public' || ($tenant->source !== 'job' && (! $tenant->actorId || ! $tenant->membershipId))
                || $current->organizationId !== $tenant->organizationId || $current->brandId !== $tenant->brandId
                || $current->actorId !== $tenant->actorId || $current->membershipId !== $tenant->membershipId || $current->source !== $tenant->source) {
                return null;
            }
            $scope = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId];
            $reference = DB::table('measurement_answers')->where($scope)->where('id', $answerId)->first(['measurement_id']);
            if (! $reference) {
                return null;
            }
            MeasurementPlan::fetch($scope, $reference->measurement_id);

            // Size and value are read in the same statement, preventing a changed
            // answer from bypassing preflight through the evaluator JOIN.
            return DB::table('measurement_answers as answer')
                ->join('measurements as measurement', function ($join): void {
                    $join->on('measurement.id', '=', 'answer.measurement_id')
                        ->on('measurement.organization_id', '=', 'answer.organization_id')
                        ->on('measurement.brand_id', '=', 'answer.brand_id');
                })->where('answer.id', $answerId)->where('answer.organization_id', $tenant->organizationId)
                ->where('answer.brand_id', $tenant->brandId)->where('answer.measurement_id', $reference->measurement_id)
                ->whereRaw('octet_length(answer.answer_text) <= ?', [WorkloadLimits::dataBytes()])
                ->whereRaw('octet_length(measurement.configuration::text) <= ?', [WorkloadLimits::planBytes()])
                ->whereRaw("octet_length((measurement.configuration->'questions')::text) <= ?", [WorkloadLimits::limit('max_questions_bytes')])
                ->whereRaw('octet_length(measurement.profile_snapshot::text) <= ?', [WorkloadLimits::dataBytes()])
                ->select('answer.status', 'answer.protocol_valid', 'answer.answer_text', 'answer.measurement_id',
                    'measurement.profile_snapshot', 'measurement.profile_checksum', 'measurement.configuration')->first();
        } catch (Throwable) {
            return null;
        }
    }
}
