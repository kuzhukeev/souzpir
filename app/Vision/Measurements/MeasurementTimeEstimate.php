<?php

namespace App\Vision\Measurements;

use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class MeasurementTimeEstimate
{
    public function forMeasurement(TenantContext $context, object $measurement, Collection $answers): ?int
    {
        if (! in_array($measurement->status, ['pending', 'running'], true) || $answers->isEmpty()) {
            return null;
        }
        $signature = $answers->map(fn ($a) => [$a->question_n, $a->provider, $a->requested_model, $a->run_no])->all();
        $previous = DB::table('measurements')->where('organization_id', $context->organizationId)
            ->where('brand_id', $context->brandId)->where('question_set_id', $measurement->question_set_id)
            ->where('status', 'completed')->whereNotNull('started_at')->whereNotNull('finished_at')
            ->where('finished_at', '<', $measurement->created_at)->orderByDesc('finished_at')->limit(10)->get(['id', 'started_at', 'finished_at']);
        foreach ($previous as $candidate) {
            $slots = DB::table('measurement_answers')->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->where('measurement_id', $candidate->id)
                ->orderBy('question_n')->orderBy('provider')->orderBy('run_no')
                ->get(['question_n', 'provider', 'requested_model', 'run_no', 'status']);
            if ($slots->contains(fn ($slot) => $slot->status !== 'ok') || $slots->map(fn ($a) => [$a->question_n, $a->provider, $a->requested_model, $a->run_no])->all() !== $signature) {
                continue;
            }
            $seconds = CarbonImmutable::parse($candidate->started_at)->diffInSeconds(CarbonImmutable::parse($candidate->finished_at), false);
            if ($seconds > 0) {
                return max(1, (int) ceil($seconds / 60));
            }
        }

        return null;
    }
}
