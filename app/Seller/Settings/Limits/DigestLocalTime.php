<?php

namespace App\Seller\Settings\Limits;

use Carbon\CarbonImmutable;
use DateTimeZone;
use LogicException;

/** Resolve one local calendar slot consistently, including DST gaps and overlaps. */
final class DigestLocalTime
{
    public function resolve(string $date, int $hour, int $minute, string $timezone): CarbonImmutable
    {
        $zone = new DateTimeZone($timezone);
        $wall = sprintf('%s %02d:%02d', $date, $hour, $minute);
        $naive = CarbonImmutable::createFromFormat('!Y-m-d H:i', $wall, 'UTC');
        if ($naive === false) {
            throw new LogicException('Invalid digest date.');
        }
        $seconds = $naive->timestamp;
        $transitions = $zone->getTransitions($seconds - 172800, $seconds + 172800);
        $offsets = $transitions === false ? [$zone->getOffset($naive)] : array_unique(array_column($transitions, 'offset'));
        $candidates = [];
        foreach ($offsets as $offset) {
            $candidate = CarbonImmutable::createFromTimestampUTC($seconds - $offset)->setTimezone($zone);
            if ($candidate->format('Y-m-d H:i') === $wall) {
                $candidates[] = $candidate;
            }
        }
        if ($candidates !== []) {
            usort($candidates, fn (CarbonImmutable $a, CarbonImmutable $b) => $a->timestamp <=> $b->timestamp);

            return $candidates[0];
        }
        // A non-existent wall time is shifted forward by the DST gap by PHP.
        $normalized = CarbonImmutable::createFromFormat('!Y-m-d H:i', $wall, $zone);
        if ($normalized === false) {
            throw new LogicException('Invalid digest local time.');
        }

        return $normalized;
    }

    public function nextDate(string $date): string
    {
        return CarbonImmutable::parse($date, 'UTC')->addDay()->toDateString();
    }
}
