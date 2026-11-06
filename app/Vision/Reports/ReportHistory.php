<?php

namespace App\Vision\Reports;

use App\Ai\CanonicalJson;

final class ReportHistory
{
    public function signature(array $payload): ?string
    {
        if (! is_string($payload['question_set_checksum'] ?? null) || $payload['question_set_checksum'] === ''
            || ! is_array($payload['providers'] ?? null) || $payload['providers'] === []
            || ! is_int($payload['runs'] ?? null) || $payload['runs'] < 1) {
            return null;
        }

        return CanonicalJson::hash(['questions' => $payload['question_set_checksum'], 'providers' => $payload['providers'], 'runs' => $payload['runs']]);
    }

    public function point(object $report, array $payload): ?array
    {
        $value = data_get($payload, 'metrics.visibility.macro_percent');
        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
            return null;
        }

        return ['measurement_id' => $report->measurement_id, 'date' => $report->measurement_finished_at ?? $report->measurement_created_at, 'visibility' => (float) $value];
    }
}
