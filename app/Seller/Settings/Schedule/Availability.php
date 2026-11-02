<?php

namespace App\Seller\Settings\Schedule;

use Carbon\CarbonImmutable;

final class Availability
{
    public function forTime(array $settings, CarbonImmutable $now, string $timezone, ?string $channelMode = null): array
    {
        $mode = $channelMode ?? ($settings['mode'] ?? 'autonomous');
        if (! in_array($mode, ['autonomous', 'suggest', 'off'], true)) {
            return ['mode' => 'off', 'notify_managers' => false];
        }
        if ($mode === 'off') {
            return ['mode' => 'off', 'notify_managers' => false];
        }
        $local = $now->setTimezone($timezone);
        if (in_array($local->toDateString(), $settings['holidays'] ?? [], true)) {
            return ['mode' => 'off', 'notify_managers' => false];
        }
        $week = $settings['week'] ?? [];
        if ($week === []) {
            return ['mode' => $mode, 'notify_managers' => true];
        }
        $day = $week[$local->dayOfWeekIso - 1] ?? null;
        if (! is_array($day) || ! ($day['enabled'] ?? false)) {
            return ['mode' => 'off', 'notify_managers' => false];
        }
        $time = $local->format('H:i');
        if ($time < ($day['start'] ?? '00:00') || $time >= ($day['end'] ?? '00:00')) {
            return ['mode' => 'off', 'notify_managers' => false];
        }
        if (($day['behavior'] ?? 'bot') === 'off') {
            return ['mode' => 'off', 'notify_managers' => false];
        }

        return ['mode' => $mode, 'notify_managers' => ($day['behavior'] ?? 'bot') !== 'quiet'];
    }
}
