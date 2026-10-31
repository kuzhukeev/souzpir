<?php

namespace App\Vision\Core;

use InvalidArgumentException;
use LogicException;

/** Resource safety limits only; commercial entitlements are checked separately. */
final class WorkloadLimits
{
    public static function limit(string $name): int
    {
        $caps = ['max_questions' => 10000, 'max_questions_bytes' => 10000000, 'max_slots' => 1000000];
        $value = config('features.E8-01.defaults.workload.'.$name);
        if (! isset($caps[$name]) || ! is_int($value) || $value < 1 || $value > $caps[$name]) {
            throw new LogicException('Vision workload safeguards are not configured.');
        }

        return $value;
    }

    public static function grid(int $questions, int $providers, int $runs): void
    {
        $slots = self::limit('max_slots');
        if ($questions < 1 || $questions > self::limit('max_questions') || $providers < 1 || $runs < 1
            || $providers > $slots || $questions > intdiv($slots, $providers)
            || $runs > intdiv(intdiv($slots, $providers), $questions)) {
            throw new InvalidArgumentException('Vision measurement workload exceeds the technical limit.');
        }
    }
}
