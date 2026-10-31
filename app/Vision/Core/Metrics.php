<?php

namespace App\Vision\Core;

final class Metrics
{
    public static function readiness(array $criteria): array
    {
        $lower = 0;
        $unknown = 0;
        $seen = [];
        foreach ($criteria as $index => $criterion) {
            $id = (int) ($criterion['id'] ?? ($index + 1));
            if ($id < 1 || $id > 20 || isset($seen[$id])) {
                throw new \InvalidArgumentException('Readiness requires unique criterion IDs 1–20');
            }$seen[$id] = true;
            $score = $criterion['score'] ?? null;
            $missing = $criterion['unknown_weight'] ?? ($score === null ? 1.0 : 0.0);
            $value = $criterion['lower'] ?? $score ?? 0;
            if (! is_numeric($value) || ! is_numeric($missing) || $value < 0 || $missing < 0 || $value + $missing > 1.00000001) {
                throw new \InvalidArgumentException('Invalid readiness bounds');
            }
            $lower += 5 * (float) $value;
            $unknown += (float) $missing;
        }
        $unknown += 20 - count($seen);

        return ['lower' => round($lower, 2), 'upper' => round($lower + 5 * $unknown, 2), 'coverage' => round(100 * (1 - $unknown / 20), 2), 'display' => $unknown ? round($lower).'–'.round($lower + 5 * $unknown) : (string) round($lower)];
    }

    /** Cells carry alpha_q/r_q. Missing results never count as a negative answer. */
    public static function visibility(array $providers, int $totalServices = 10): array
    {
        $observed = 0;
        $numerator = 0;
        $valid = 0;
        $positive = 0;
        $scores = [];
        if ($totalServices < 1 || count($providers) > $totalServices) {
            throw new \InvalidArgumentException('Provider count exceeds the planned panel');
        }
        foreach ($providers as $provider) {
            $o = 0;
            $n = 0;
            foreach ($provider['cells'] ?? [] as $cell) {
                if (! isset($cell['weight']) || ! is_numeric($cell['weight']) || $cell['weight'] < 0 || $cell['weight'] > 1) {
                    throw new \InvalidArgumentException('Invalid scenario weight');
                }
                if (! ($cell['valid'] ?? false)) {
                    continue;
                }
                $o += $cell['weight'];
                $valid++;
                if ($cell['positive'] ?? false) {
                    $n += $cell['weight'];
                    $positive++;
                }
            }
            if ($o > 1.00000001) {
                throw new \InvalidArgumentException('Service observation weights exceed one');
            }
            $observed += $o;
            $numerator += $n;
            if ($o > 0) {
                $scores[] = 100 * $n / $o;
            }
        }
        $coverage = (100 / $totalServices) * $observed;
        $lower = (100 / $totalServices) * $numerator;
        $complete = count($scores) === $totalServices && abs($observed - $totalServices) < 0.00000001;

        return ['available_services' => count($scores), 'total_services' => $totalServices, 'macro_percent' => $scores ? round(array_sum($scores) / count($scores), 2) : null, 'macro_kind' => $complete ? 'full' : 'available', 'micro_percent' => $valid ? round(100 * $positive / $valid, 2) : null, 'coverage' => round($coverage, 2), 'lower' => round($lower, 2), 'upper' => round($lower + 100 - $coverage, 2), 'status' => $complete ? 'complete' : 'partial'];
    }

    public static function percentile(float $score, array $competitors): ?float
    {
        if (! $competitors) {
            return null;
        }

        return round(100 * (count(array_filter($competitors, fn ($x) => (float) $x < $score)) + .5 * count(array_filter($competitors, fn ($x) => (float) $x === $score))) / count($competitors), 2);
    }

    /** Compare only fully known criteria shared by every included snapshot/site. */
    public static function commonMask(array $sets): array
    {
        if (! $sets) {
            return [];
        }$common = null;
        foreach ($sets as $criteria) {
            $known = [];
            foreach ($criteria as $c) {
                if (($c['score'] ?? null) !== null && (float) ($c['unknown_weight'] ?? 0) === 0.0) {
                    $known[] = (int) $c['id'];
                }
            }$common = $common === null ? $known : array_intersect($common, $known);
        }
        $common = array_values($common ?? []);
        sort($common);

        return $common;
    }

    public static function onMask(array $criteria, array $mask): float
    {
        $sum = 0;
        foreach ($criteria as $c) {
            if (in_array((int) $c['id'], $mask, true)) {
                $sum += 5 * (float) $c['score'];
            }
        }

        return round($sum, 2);
    }

    public static function pairedReadiness(array $previous, array $current): array
    {
        $mask = self::commonMask([$previous, $current]);
        $before = self::onMask($previous, $mask);
        $after = self::onMask($current, $mask);

        return ['criterion_ids' => $mask, 'coverage' => count($mask) * 5, 'before' => $mask ? $before : null, 'after' => $mask ? $after : null, 'delta' => $mask ? round($after - $before, 2) : null, 'maximum' => count($mask) * 5];
    }

    public static function panel(array $brand, array $competitors): array
    {
        $sets = [$brand];
        foreach ($competitors as $c) {
            $sets[] = $c['criteria'];
        }$mask = self::commonMask($sets);
        $base = self::onMask($brand, $mask);
        $values = [];
        $rows = [];
        foreach ($competitors as $c) {
            $score = self::onMask($c['criteria'], $mask);
            $values[] = $score;
            $rows[] = ['name' => $c['name'], 'website' => $c['website'], 'score' => $mask ? $score : null];
        }
        $higher = count(array_filter($values, fn ($s) => $s > $base));
        $equal = count(array_filter($values, fn ($s) => $s === $base));

        return ['criterion_ids' => $mask, 'coverage' => count($mask) * 5, 'maximum' => count($mask) * 5, 'brand_score' => $mask ? $base : null, 'rank_min' => $mask && $values ? 1 + $higher : null, 'rank_max' => $mask && $values ? 1 + $higher + $equal : null, 'percentile' => $mask ? self::percentile($base, $values) : null, 'competitors' => $rows, 'panel_size' => count($values), 'label' => 'Сравнение только по общим известным критериям; неизвестное не равно нулю.'];
    }
}
