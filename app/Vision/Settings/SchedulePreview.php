<?php

namespace App\Vision\Settings;

use Carbon\CarbonImmutable;

final class SchedulePreview
{
    /** Counts future occurrences from the saved schedule, with an inclusive local-date range. */
    public function estimate(array $schedule, ?int $questions, string $from, string $to): ?array
    {
        if (! ($schedule['enabled'] ?? false)) {
            return ['runs' => 0, 'slots' => 0];
        }
        if (! isset($schedule['next_run_at'], $schedule['timezone'], $schedule['frequency'], $schedule['weekday'], $schedule['local_time'])
            || $questions === null || $questions < 1 || empty($schedule['models'])
            || ! in_array($schedule['frequency'], ['weekly', 'biweekly', 'monthly'], true)) {
            return null;
        }
        $timezone = $schedule['timezone'];
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, $timezone);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $to, $timezone)->endOfDay();
        $current = CarbonImmutable::parse($schedule['next_run_at'])->setTimezone($timezone);
        $now = CarbonImmutable::now($timezone);
        $count = 0;
        while ($current->lessThanOrEqualTo($end)) {
            if ($current->greaterThanOrEqualTo($start) && $current->greaterThanOrEqualTo($now)) {
                $count++;
            }
            if ($schedule['frequency'] === 'monthly') {
                $current = $current->addMonthNoOverflow()->startOfMonth()->setTimeFromTimeString($schedule['local_time']);
                while ($current->dayOfWeekIso !== (int) $schedule['weekday']) {
                    $current = $current->addDay();
                }
            } else {
                $current = $current->addWeeks($schedule['frequency'] === 'biweekly' ? 2 : 1);
            }
        }

        return ['runs' => $count, 'slots' => $count * $questions * count($schedule['models'])];
    }

    public function nextThree(array $schedule): array
    {
        if (! $schedule['enabled'] || ! $schedule['next_run_at']) {
            return [];
        }

        $timezone = $schedule['timezone'];
        $current = CarbonImmutable::parse($schedule['next_run_at'])->setTimezone($timezone);
        $dates = [];
        for ($index = 0; $index < 3; $index++) {
            $dates[] = $current->format('Y-m-d H:i');
            if ($schedule['frequency'] === 'monthly') {
                $current = $current->addMonthNoOverflow()->startOfMonth()->setTimeFromTimeString($schedule['local_time']);
                while ($current->dayOfWeekIso !== (int) $schedule['weekday']) {
                    $current = $current->addDay();
                }
            } else {
                $current = $current->addWeeks($schedule['frequency'] === 'biweekly' ? 2 : 1);
            }
        }

        return $dates;
    }
}
