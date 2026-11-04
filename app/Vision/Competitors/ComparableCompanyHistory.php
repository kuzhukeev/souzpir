<?php

namespace App\Vision\Competitors;

final class ComparableCompanyHistory
{
    /** @return array<string, int>|null Change in number of answers mentioning each company. */
    public function deltas(array $before, array $after, array $aliases): ?array
    {
        if (! is_string($after['question_set_checksum'] ?? null) || $after['question_set_checksum'] === ''
            || ($before['question_set_checksum'] ?? null) !== $after['question_set_checksum']
            || ! is_array($before['providers'] ?? null) || ! is_array($after['providers'] ?? null)
            || $before['providers'] !== $after['providers'] || ! is_int($after['runs'] ?? null)
            || $after['runs'] < 1 || ($before['runs'] ?? null) !== $after['runs']) {
            return null;
        }
        $old = $this->slots($before, $aliases);
        $new = $this->slots($after, $aliases);
        if ($old === null || $new === null || array_keys($old) !== array_keys($new)) {
            return null;
        }
        $counts = [];
        foreach ([$old, $new] as $index => $slots) {
            $counts[$index] = [];
            foreach ($slots as $names) {
                foreach ($names as $name) {
                    $counts[$index][$name] = ($counts[$index][$name] ?? 0) + 1;
                }
            }
        }
        $result = [];
        foreach (array_unique([...array_keys($counts[0]), ...array_keys($counts[1])]) as $name) {
            $result[$name] = ($counts[1][$name] ?? 0) - ($counts[0][$name] ?? 0);
        }

        return $result;
    }

    private function slots(array $snapshot, array $aliases): ?array
    {
        $slots = data_get($snapshot, 'metrics.slots');
        $questions = $snapshot['questions'] ?? null;
        $providers = $snapshot['providers'] ?? null;
        if (! is_array($slots) || ! is_array($questions) || ! is_array($providers) || ! count($questions) || ! count($providers)
            || count($slots) !== count($questions) * count($providers) * $snapshot['runs']) {
            return null;
        }
        $result = [];
        foreach ($slots as $slot) {
            if (! is_array($slot) || ($slot['valid'] ?? false) !== true || ($slot['mentions_complete'] ?? false) !== true
                || ! is_string($slot['qid'] ?? null) || ! is_string($slot['provider'] ?? null) || ! is_int($slot['run_no'] ?? null)
                || ! is_array($slot['mentions'] ?? null)) {
                return null;
            }
            $key = json_encode([$slot['qid'], $slot['provider'], $slot['run_no']], JSON_THROW_ON_ERROR);
            if (array_key_exists($key, $result)) {
                return null;
            }
            $names = [];
            foreach ($slot['mentions'] as $mention) {
                $name = $mention['name'] ?? null;
                if (! is_string($name) || $name === '' || ($mention['ambiguous_brand'] ?? false)) {
                    return null;
                }
                $names[] = $aliases[$name] ?? $name;
            }
            $result[$key] = array_values(array_unique($names));
        }
        ksort($result, SORT_STRING);

        return $result;
    }
}
