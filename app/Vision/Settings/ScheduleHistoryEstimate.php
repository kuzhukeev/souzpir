<?php

namespace App\Vision\Settings;

use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ScheduleHistoryEstimate
{
    public function estimate(TenantContext $tenant, array $current, ?int $questions, string $from, string $to): ?array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, $current['timezone']);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $to, $current['timezone'])->addDay();
        $versions = DB::table('vision_schedule_versions')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
            ->where('effective_at', '<', $end->utc())->orderBy('effective_at')->orderBy('id')->get();
        if ($versions->isEmpty() || CarbonImmutable::parse($versions->first()->effective_at)->greaterThan($start)) {
            if ($versions->isEmpty() && $start->greaterThan(CarbonImmutable::now($current['timezone'])->startOfDay())) {
                return app(SchedulePreview::class)->estimate($current, $questions, $from, $to);
            }

            return null;
        }
        $runs = 0;
        $slots = 0;
        foreach ($versions as $index => $version) {
            $schedule = json_decode($version->schedule, true, 32, JSON_THROW_ON_ERROR);
            if (! ($schedule['enabled'] ?? false)) {
                continue;
            }
            if ($questions === null || $questions < 1 || empty($schedule['models']) || empty($schedule['next_run_at']) || empty($schedule['timezone']) || empty($schedule['frequency']) || empty($schedule['weekday']) || empty($schedule['local_time'])) {
                return null;
            }
            $validFrom = CarbonImmutable::parse($version->effective_at);
            $validUntil = isset($versions[$index + 1]) ? CarbonImmutable::parse($versions[$index + 1]->effective_at) : $end;
            $date = CarbonImmutable::parse($schedule['next_run_at'])->setTimezone($schedule['timezone']);
            while ($date->lessThan($end) && $date->lessThan($validUntil)) {
                if ($date->greaterThanOrEqualTo($start) && $date->greaterThanOrEqualTo($validFrom)) {
                    $runs++;
                    $slots += $questions * count($schedule['models']);
                }
                if ($schedule['frequency'] === 'monthly') {
                    $date = $date->addMonthNoOverflow()->startOfMonth()->setTimeFromTimeString($schedule['local_time']);
                    while ($date->dayOfWeekIso !== (int) $schedule['weekday']) {
                        $date = $date->addDay();
                    }
                } elseif (in_array($schedule['frequency'], ['weekly', 'biweekly'], true)) {
                    $date = $date->addWeeks($schedule['frequency'] === 'biweekly' ? 2 : 1);
                } else {
                    return null;
                }
            }
        }

        return ['runs' => $runs, 'slots' => $slots];
    }
}
