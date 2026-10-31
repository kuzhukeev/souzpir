<?php

namespace App\Vision\SiteAudit;

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
            if (! is_numeric($value) || ! is_numeric($missing) || ! is_finite((float) $value) || ! is_finite((float) $missing) || $value < 0 || $missing < 0 || $value + $missing > 1.00000001) {
                throw new \InvalidArgumentException('Invalid readiness bounds');
            }
            $lower += 5 * (float) $value;
            $unknown += (float) $missing;
        }
        $unknown += 20 - count($seen);

        return ['lower' => round($lower, 2), 'upper' => round($lower + 5 * $unknown, 2), 'coverage' => round(100 * (1 - $unknown / 20), 2), 'display' => $unknown ? round($lower).'–'.round($lower + 5 * $unknown) : (string) round($lower)];
    }
}
