<?php

namespace App\Vision\Settings;

use Carbon\CarbonImmutable;

final class SchedulePreview
{
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
