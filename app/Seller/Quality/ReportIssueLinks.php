<?php

namespace App\Seller\Quality;

use Illuminate\Support\Collection;

final class ReportIssueLinks
{
    /** Recover a legacy link only from a persisted evaluation reference. */
    public function enrich(Collection $reports, Collection $evaluations): Collection
    {
        $fields = ['type', 'layer', 'severity', 'turn', 'quote', 'explanation', 'fix'];

        return $reports->map(function (array $report) use ($evaluations, $fields): array {
            $report['issues'] = array_map(function (array $issue) use ($evaluations, $fields): array {
                if (! empty($issue['conversation_id']) || empty($issue['evaluation_id']) || count(array_intersect($fields, array_keys($issue))) !== count($fields)) {
                    return $issue;
                }
                $matches = $evaluations->filter(function (array $evaluation) use ($issue, $fields): bool {
                    if (empty($evaluation['conversation_id']) || ($evaluation['id'] ?? null) !== $issue['evaluation_id']) {
                        return false;
                    }
                    foreach ($evaluation['issues'] as $candidate) {
                        if (collect($fields)->every(fn ($field) => array_key_exists($field, $candidate) && $candidate[$field] === $issue[$field])) {
                            return true;
                        }
                    }

                    return false;
                })->pluck('conversation_id')->unique()->values();
                if ($matches->count() === 1) {
                    $issue['conversation_id'] = $matches->first();
                }

                return $issue;
            }, $report['issues']);

            return $report;
        });
    }
}
