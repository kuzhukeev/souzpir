<?php

namespace App\Vision\Core;

use App\Ai\CanonicalJson;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Bounds a persisted plan before loading or decoding it, including legacy rows. */
final class MeasurementPlan
{
    public static function fetch(array $tenant, string $measurementId): object
    {
        $sizes = DB::table('measurements')->where($tenant)->where('id', $measurementId)->first([
            DB::raw('octet_length(configuration::text) as plan_bytes'),
            DB::raw("octet_length((configuration->'questions')::text) as question_bytes"),
            DB::raw('octet_length(profile_snapshot::text) as profile_bytes'),
        ]);
        if (! $sizes || ! is_numeric($sizes->plan_bytes) || ! is_numeric($sizes->question_bytes)
            || (int) $sizes->plan_bytes > WorkloadLimits::planBytes()
            || (int) $sizes->question_bytes > WorkloadLimits::limit('max_questions_bytes')
            || ($sizes->profile_bytes !== null && (! is_numeric($sizes->profile_bytes) || (int) $sizes->profile_bytes > WorkloadLimits::dataBytes()))) {
            throw new LogicException('Vision measurement plan is unavailable.');
        }
        // Extract only scalar shape metadata; reject oversized grids before fetching JSON.
        $shape = DB::table('measurements')->where($tenant)->where('id', $measurementId)->first([
            DB::raw("jsonb_array_length(CASE WHEN jsonb_typeof(configuration->'questions') = 'array' THEN configuration->'questions' ELSE '[]'::jsonb END) as question_count"),
            DB::raw("(select count(*) from jsonb_object_keys(CASE WHEN jsonb_typeof(configuration->'providers') = 'object' THEN configuration->'providers' ELSE '{}'::jsonb END)) as provider_count"),
            DB::raw("CASE WHEN jsonb_typeof(configuration->'runs') = 'number' AND (configuration->>'runs') ~ '^[1-9][0-9]{0,8}$' THEN (configuration->>'runs')::integer ELSE NULL END as runs"),
        ]);
        if (! $shape || ! is_numeric($shape->question_count) || ! is_numeric($shape->provider_count) || ! is_numeric($shape->runs)) {
            throw new LogicException('Vision measurement plan is unavailable.');
        }
        WorkloadLimits::grid((int) $shape->question_count, (int) $shape->provider_count, (int) $shape->runs);
        $measurement = DB::table('measurements')->where($tenant)->where('id', $measurementId)
            ->whereRaw('octet_length(configuration::text) <= ?', [WorkloadLimits::planBytes()])
            ->whereRaw("octet_length((configuration->'questions')::text) <= ?", [WorkloadLimits::limit('max_questions_bytes')])
            ->whereRaw('(profile_snapshot is null or octet_length(profile_snapshot::text) <= ?)', [WorkloadLimits::dataBytes()])
            ->first();
        if (! $measurement || ! is_string($measurement->configuration)) {
            throw new LogicException('Vision measurement plan is unavailable.');
        }
        $plan = json_decode($measurement->configuration, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($plan) || ! is_array($plan['questions'] ?? null) || ! is_array($plan['providers'] ?? null)
            || ! is_int($plan['runs'] ?? null) || ! is_string($plan['question_set_checksum'] ?? null)) {
            throw new LogicException('Vision measurement plan is unavailable.');
        }
        WorkloadLimits::grid(count($plan['questions']), count($plan['providers']), $plan['runs']);
        if (! hash_equals($plan['question_set_checksum'], CanonicalJson::hash($plan['questions']))) {
            throw new LogicException('Vision measurement plan is unavailable.');
        }

        return $measurement;
    }
}
