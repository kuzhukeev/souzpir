<?php

namespace App\Seller\Followups;

use Carbon\CarbonImmutable;

final class FollowupPolicy
{
    public function due(array $conversation, array $settings, ?CarbonImmutable $lastAgent, CarbonImmutable $now, string $timezone): ?array
    {
        if (in_array($conversation['stage'] ?? '', ['handoff', 'lost', 'done', 'spam'], true)
            || ($conversation['do_not_disturb'] ?? false) || data_get($conversation, 'state.flags.do_not_disturb', false)
            || ! ($conversation['last_client_message_at'] ?? null)) {
            return null;
        }
        $count = (int) data_get($conversation, 'state.counters.followups_sent', $conversation['followups_sent'] ?? 0);
        $maximum = max(0, (int) ($settings['max_auto'] ?? 2));
        if ($count >= $maximum) {
            return null;
        }
        $window = $settings['window'] ?? null;
        if (is_array($window) && isset($window['start'], $window['end'])) {
            $local = $now->setTimezone($timezone)->format('H:i');
            $start = $window['start'];
            $end = $window['end'];
            if (! is_string($start) || ! is_string($end) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end)
                || ($start <= $end ? ($local < $start || $local >= $end) : ($local < $start && $local >= $end))) {
                return null;
            }
        }
        $agreed = data_get($conversation, 'state.slots.next_contact_at');
        if (is_string($agreed) && $agreed !== '') {
            try {
                $next = CarbonImmutable::parse($agreed, $timezone);
                if ($next->lessThanOrEqualTo($now) && ! data_get($conversation, 'state.flags._followup_done_'.$next->timestamp, false)) {
                    return ['reason' => 'next_contact_at', 'source_at' => $next->utc()->toIso8601String(), 'sequence' => $count + 1];
                }
            } catch (\Throwable) {
                // Invalid extracted date does not schedule an agreed contact.
            }
        }
        $lastClient = CarbonImmutable::parse($conversation['last_client_message_at']);
        if (! $lastAgent || $lastAgent->lessThanOrEqualTo($lastClient)) {
            return null;
        }
        $first = max(1, (int) ($settings['first_hours'] ?? 20));
        $hours = $count === 0 ? $first : (($settings['second_hours'] ?? null) !== null ? max(1, (int) $settings['second_hours']) : $first * ($count + 1));
        if ($lastAgent->addHours($hours)->greaterThan($now)) {
            return null;
        }

        return ['reason' => 'silence', 'source_at' => $lastAgent->utc()->toIso8601String(), 'sequence' => $count + 1];
    }
}
