<?php

namespace App\Vision\Publications;

use App\Vision\Core\VisionReportReader;

final class PublicationData
{
    public function fromReport(string $reportId): array
    {
        $source = app(VisionReportReader::class)->read($reportId);

        return $this->fromPayload($source);
    }

    public function fromPayload(array $source): array
    {
        $metrics = $source['metrics'] ?? [];
        $visibility = $metrics['visibility'] ?? [];
        $panel = $metrics['panel'] ?? [];

        return [
            'visibility' => $this->number($visibility['macro_percent'] ?? null),
            'coverage' => $this->number($visibility['coverage'] ?? null),
            'share_of_voice' => $this->number($panel['share_of_voice'] ?? null),
            'position' => $this->number($panel['position_average'] ?? null),
            'questions' => count($source['questions'] ?? []),
        ];
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? round((float) $value, 2) : null;
    }
}
