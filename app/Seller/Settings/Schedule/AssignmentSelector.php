<?php

namespace App\Seller\Settings\Schedule;

final class AssignmentSelector
{
    /** @param list<string> $eligible */
    public function select(string $method, array $eligible, ?string $last = null, ?string $fixed = null): ?string
    {
        $eligible = array_values(array_unique($eligible));
        sort($eligible, SORT_STRING);
        if ($method === 'first_taken' || ! $eligible) {
            return null;
        }
        if ($method === 'fixed') {
            return $fixed !== null && in_array($fixed, $eligible, true) ? $fixed : null;
        }
        if ($method !== 'round_robin') {
            return null;
        }
        $index = $last === null ? false : array_search($last, $eligible, true);

        return $eligible[$index === false ? 0 : (($index + 1) % count($eligible))];
    }
}
