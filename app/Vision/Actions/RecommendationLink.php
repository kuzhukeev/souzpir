<?php

namespace App\Vision\Actions;

use App\Tenancy\TenantContext;
use App\Vision\Core\VisionReportReader;
use App\Vision\Site\SiteOperations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecommendationLink
{
    public function attach(TenantContext $tenant, Request $request, string $actionId, string $reportId, int $index): void
    {
        app(SiteOperations::class)->authorize($tenant, $request);
        $scope = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId];
        abort_unless(DB::table('actions')->where($scope)->where('id', $actionId)->exists(), 404);
        $report = DB::table('vision_reports')->where($scope)->where('id', $reportId)->first(['measurement_id']);
        abort_unless($report && DB::table('snapshot_confirmations')->where($scope)->where('measurement_id', $report->measurement_id)->exists(), 404);
        $payload = app(VisionReportReader::class)->read($reportId);
        $items = data_get($payload, 'recommendations.items', []);
        $item = is_array($items) && $index >= 0 ? ($items[$index] ?? null) : null;
        if (! is_array($item) || ! is_string($item['title'] ?? null) || trim($item['title']) === '') {
            throw ValidationException::withMessages(['recommendation' => 'Рекомендация отсутствует в подтверждённом отчёте.']);
        }
        $strings = fn ($values) => array_values(array_filter(is_array($values) ? $values : [], fn ($value) => is_string($value) && trim($value) !== ''));
        $questions = $item['question_ids'] ?? (isset($item['question_id']) ? [$item['question_id']] : (isset($item['qid']) ? [$item['qid']] : []));
        $metadata = ['priority' => is_string($item['priority'] ?? null) ? $item['priority'] : null, 'steps' => $strings($item['steps'] ?? []), 'question_ids' => $strings($questions)];
        DB::table('vision_action_recommendation_links')->updateOrInsert(['action_id' => $actionId], [...$scope, 'report_id' => $reportId, 'recommendation_index' => $index, 'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
    }
}
