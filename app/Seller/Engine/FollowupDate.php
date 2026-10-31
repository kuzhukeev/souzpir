<?php

namespace App\Seller\Engine;

use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;
use SensitiveParameter;
use Throwable;

/** A date candidate is neither proof of client consent nor permission to perform an action. */
final class FollowupDate
{
    /**
     * The caller supplies the trusted clock and timezone. Unknown or ambiguous
     * input never produces an execution date, including the donor's fallback.
     *
     * @return array{status:string,date:?CarbonImmutable,source:?string,time_defaulted:bool,day_defaulted:bool,year_defaulted:bool,reason:?string}
     */
    public function parse(#[SensitiveParameter] string $raw, CarbonImmutable $now): array
    {
        try {
            if (strlen($raw) > 16384 || ! mb_check_encoding($raw, 'UTF-8')) {
                throw new InvalidArgumentException;
            }
            if ($this->matches('/\A(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2})(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})?)?\z/', $raw, $iso)) {
                return $this->iso($iso, $now);
            }
            $input = trim(mb_strtolower($raw, 'UTF-8'));
            // Calendar arithmetic in UTC preserves the requested local date even
            // when a timezone skips a whole civil day (for example Pacific/Apia).
            $localDay = CarbonImmutable::create($now->year, $now->month, $now->day, 0, 0, 0, 'UTC');
            if ($input === 'сегодня') {
                return $this->candidate($now->addHours(3), $now, 'relative', true);
            }
            if (in_array($input, ['завтра', 'на завтра', 'послезавтра', 'на послезавтра'], true)) {
                return $this->localCandidate($localDay->addDays(str_contains($input, 'послезавтра') ? 2 : 1), $now, 'relative');
            }
            $days = ['понедельник' => 1, 'вторник' => 2, 'среду' => 3, 'среда' => 3, 'четверг' => 4, 'пятницу' => 5, 'пятница' => 5, 'субботу' => 6, 'суббота' => 6, 'воскресенье' => 0];
            $dayName = str_starts_with($input, 'в ') ? mb_substr($input, 2) : $input;
            if (array_key_exists($dayName, $days)) {
                return $this->localCandidate($localDay->next($days[$dayName]), $now, 'weekday');
            }
            if ($this->matches('/\Aчерез\s+(\d+)\s+д(?:ень|ня|ней)\z/u', $input, $relative)) {
                $digits = ltrim($relative[1], '0');
                $count = filter_var($digits === '' ? '0' : $digits, FILTER_VALIDATE_INT);
                if ($count === false || $count > intdiv(PHP_INT_MAX, 86400) - 1) {
                    throw new InvalidArgumentException;
                }
                $date = $localDay->addDays($count);
                $expectedTimestamp = $localDay->timestamp + $count * 86400;
                if (! is_int($expectedTimestamp) || $date->timestamp !== $expectedTimestamp
                    || ($count > 0 && $date->lessThanOrEqualTo($localDay))) {
                    throw new InvalidArgumentException;
                }

                return $this->localCandidate($date, $now, 'relative');
            }
            if ($input === 'через неделю') {
                return $this->localCandidate($localDay->addWeek(), $now, 'relative');
            }
            if ($this->matches('/\A(\d{1,2})[.\/](\d{1,2})\z/', $input, $numeric)) {
                $day = (int) $numeric[1];
                $month = (int) $numeric[2];

                return $this->annualCandidate($month, $day, $now, 'numeric');
            }

            return $this->month($input, $now);
        } catch (Throwable) {
            // Do not chain parser exceptions that can contain a private client message.
            throw new InvalidArgumentException('Follow-up date could not be safely prepared.');
        }
    }

    private function iso(#[SensitiveParameter] array $parts, CarbonImmutable $now): array
    {
        [, $y, $m, $d] = $parts;
        $hasTime = isset($parts[4]) && $parts[4] !== '';
        $hour = $hasTime ? (int) $parts[4] : 0;
        $minute = $hasTime ? (int) $parts[5] : 0;
        $second = isset($parts[6]) && $parts[6] !== '' ? (int) $parts[6] : 0;
        $fraction = $parts[7] ?? '';
        $microseconds = $fraction === '' ? 0 : (int) str_pad(substr($fraction, 1), 6, '0');
        $offset = $parts[8] ?? '';
        if (! checkdate((int) $m, (int) $d, (int) $y) || $hour > 23 || $minute > 59 || $second > 59
            || $offset === '-00:00'
            || ($offset !== '' && $offset !== 'Z' && ((int) substr($offset, 1, 2) > 23 || (int) substr($offset, 4, 2) > 59))) {
            return $this->clarify('invalid_date');
        }
        $local = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $m, $d, $hour, $minute, $second);
        $date = $offset !== ''
            ? CarbonImmutable::parse($local.$offset)
            : $this->resolveLocal($local, $now->getTimezone());
        if ($date === null) {
            return $this->clarify('ambiguous_local_time');
        }

        return $this->candidate($date->setMicrosecond($microseconds), $now, 'iso', ! $hasTime);
    }

    private function month(#[SensitiveParameter] string $input, CarbonImmutable $now): array
    {
        // The donor's "конец" stem can yield the fifth day; never make that a candidate.
        $months = ['январ(?:ь|я|е)' => 1, 'феврал(?:ь|я|е)' => 2, 'март(?:а|е)?' => 3, 'апрел(?:ь|я|е)' => 4, 'ма(?:й|я|е)' => 5,
            'июн(?:ь|я|е)' => 6, 'июл(?:ь|я|е)' => 7, 'август(?:а|е)?' => 8, 'сентябр(?:ь|я|е)' => 9,
            'октябр(?:ь|я|е)' => 10, 'ноябр(?:ь|я|е)' => 11, 'декабр(?:ь|я|е)' => 12];
        foreach ($months as $pattern => $month) {
            if ($this->matches('/\A(?:(?:в\s+)?(начало|начале|середина|середине)\s+)?'.$pattern.'\z/u', $input, $matches)) {
                $day = str_starts_with($matches[1] ?? '', 'серед') ? 15 : 5;

                return $this->annualCandidate($month, $day, $now, 'month');
            }
        }

        return $this->clarify('unrecognized_or_ambiguous');
    }

    private function annualCandidate(int $month, int $day, CarbonImmutable $now, string $source): array
    {
        $year = $now->year;
        if (! checkdate($month, $day, $year)) {
            return $this->clarify('invalid_date');
        }
        $date = $this->resolveLocal(sprintf('%04d-%02d-%02d 11:00:00', $year, $month, $day), $now->getTimezone());
        if ($date === null) {
            return $this->clarify('ambiguous_local_time');
        }
        if ($date->lessThan($now)) {
            if (! checkdate($month, $day, ++$year)) {
                return $this->clarify('invalid_date');
            }
            $date = $this->resolveLocal(sprintf('%04d-%02d-%02d 11:00:00', $year, $month, $day), $now->getTimezone());
            if ($date === null) {
                return $this->clarify('ambiguous_local_time');
            }
        }

        return $this->candidate($date, $now, $source, true, $source === 'month', true);
    }

    private function localCandidate(CarbonImmutable $day, CarbonImmutable $now, string $source): array
    {
        $local = $day->format('Y-m-d').' 11:00:00';
        $date = $this->resolveLocal($local, $now->getTimezone());

        return $date === null ? $this->clarify('ambiguous_local_time') : $this->candidate($date, $now, $source, true);
    }

    /** Resolve a wall time only when exactly one instant exists in the supplied zone. */
    private function resolveLocal(string $local, DateTimeZone $zone): ?CarbonImmutable
    {
        $naive = CarbonImmutable::parse($local, 'UTC');
        $transitions = $zone->getTransitions($naive->timestamp - 172800, $naive->timestamp + 172800);
        $offsets = $transitions === false ? [$zone->getOffset($naive)] : array_column($transitions, 'offset');
        $instants = [];
        foreach (array_unique($offsets) as $offset) {
            $instant = CarbonImmutable::createFromTimestampUTC($naive->timestamp - $offset)->setTimezone($zone);
            if ($instant->format('Y-m-d H:i:s') === $local) {
                $instants[$instant->timestamp] = $instant;
            }
        }

        return count($instants) === 1 ? reset($instants) : null;
    }

    private function candidate(CarbonImmutable $date, CarbonImmutable $now, string $source, bool $timeDefaulted, bool $dayDefaulted = false, bool $yearDefaulted = false): array
    {
        if ($date->lessThanOrEqualTo($now)) {
            return $this->clarify('not_future');
        }

        return ['status' => 'candidate', 'date' => $date, 'source' => $source, 'time_defaulted' => $timeDefaulted, 'day_defaulted' => $dayDefaulted, 'year_defaulted' => $yearDefaulted, 'reason' => null];
    }

    private function clarify(string $reason): array
    {
        return ['status' => 'needs_clarification', 'date' => null, 'source' => null, 'time_defaulted' => false, 'day_defaulted' => false, 'year_defaulted' => false, 'reason' => $reason];
    }

    private function matches(string $pattern, #[SensitiveParameter] string $value, ?array &$matches = null): bool
    {
        $result = preg_match($pattern, $value, $matches);
        if ($result === false) {
            throw new InvalidArgumentException;
        }

        return $result === 1;
    }
}
