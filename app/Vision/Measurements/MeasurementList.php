<?php

namespace App\Vision\Measurements;

use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Tenancy\TenantContext;
use App\Vision\Core\VisionReportReader;
use App\Vision\Reports\ReportSections;
use App\Vision\Site\SiteAccess;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MeasurementList
{
    /** Hold the shared session lease across authorization, revision checks and the data read. */
    public function withinCurrent(#[\SensitiveParameter] Request $request, Closure $callback, bool $requireClientRevision = true): mixed
    {
        return app(AuthSessionRegistry::class)->withinActive($request, function (User $user) use ($request, $callback, $requireClientRevision): mixed {
            $context = app(TenantContext::class);
            $expected = $request->attributes->get('tenant.brand_revision');
            $selection = app(SessionBrandSelection::class)->current($request);
            abort_unless($context->source === 'session' && $context->actorId === $user->id
                && is_string($context->organizationId) && is_string($context->brandId) && is_string($context->membershipId)
                && is_string($expected) && (string) $selection->brand_revision === $expected
                && $selection->selected_organization_id === $context->organizationId
                && $selection->selected_brand_id === $context->brandId, 409);
            $revision = app(SessionBrandSelection::class)->profileRevision($request, $expected);
            if ($requireClientRevision) {
                $supplied = $request->header('X-Brand-Revision');
                abort_unless($request->header('X-Brand-Id') === $context->brandId && is_string($supplied)
                    && hash_equals($revision, $supplied), 409);
            }
            // The parent lock also prevents FK-backed insertion of a first plan assignment.
            abort_unless(DB::table('organizations')->where('id', $context->organizationId)->where('status', 'active')->lockForUpdate()->first(['id']), 403);
            // Shared access acquires brand, membership, module, assignments, plans and limits
            // in the established Vision order before testing module entitlement and ability.
            app(SiteAccess::class)->lock($context, false, 'vision.measure.read');

            return $callback($context, $revision);
        });
    }

    public function read(TenantContext $context, int $page): array
    {
        $query = DB::table('measurements as measurement')
            ->where('measurement.organization_id', $context->organizationId)->where('measurement.brand_id', $context->brandId);
        $count = (clone $query)->count();
        $rows = $query->orderByDesc('measurement.created_at')->orderByDesc('measurement.id')->forPage($page, 25)
            ->get(['measurement.created_at']);

        return ['data' => $rows->all(), 'page' => $page, 'last_page' => max(1, (int) ceil($count / 25)), 'total' => $count];
    }

    public function readDetailed(TenantContext $context, int $page, ?string $month = null): array
    {
        $query = DB::table('measurements')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId);
        if ($month !== null) {
            $start = CarbonImmutable::createFromFormat('!Y-m', $month, 'UTC');
            $query->where('created_at', '>=', $start)->where('created_at', '<', $start->addMonth());
        }
        $count = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->forPage($page, 25)
            ->get(['id', 'status', 'created_at', 'started_at', 'finished_at']);
        $answers = DB::table('measurement_answers')->where('organization_id', $context->organizationId)
            ->where('brand_id', $context->brandId)->whereIn('measurement_id', $rows->pluck('id'))
            ->orderBy('question_n')->orderBy('provider')->orderBy('run_no')
            ->get(['measurement_id', 'question_n', 'provider', 'requested_model', 'run_no', 'status', 'answer_text', 'error_code'])
            ->groupBy('measurement_id');
        $confirmed = DB::table('snapshot_confirmations')->where('organization_id', $context->organizationId)
            ->where('brand_id', $context->brandId)->whereIn('measurement_id', $rows->pluck('id'))
            ->pluck('measurement_id')->all();

        $launches = DB::table('audit_logs')->where('organization_id', $context->organizationId)
            ->where('brand_id', $context->brandId)->where('subject_type', 'measurement')
            ->whereIn('subject_id', $rows->pluck('id'))->whereIn('action', ['vision.measurement.started', 'vision.measurement.repeated'])
            ->orderByDesc('created_at')->get(['subject_id', 'action'])->unique('subject_id')->keyBy('subject_id');

        $reportIds = DB::table('vision_reports')->where('organization_id', $context->organizationId)
            ->where('brand_id', $context->brandId)->whereIn('measurement_id', $confirmed)
            ->orderByDesc('revision')->get(['id', 'measurement_id'])->unique('measurement_id');
        $reportMetrics = [];
        foreach ($reportIds as $report) {
            try {
                $payload = app(VisionReportReader::class)->read($report->id);
                $visibility = data_get($payload, 'metrics.visibility.macro_percent');
                $mentions = data_get($payload, 'metrics.panel.mentioned');
                $row = $rows->firstWhere('id', $report->measurement_id);
                $previous = DB::table('vision_reports as r')
                    ->join('measurements as m', function ($join): void {
                        $join->on('m.id', '=', 'r.measurement_id')->on('m.brand_id', '=', 'r.brand_id')->on('m.organization_id', '=', 'r.organization_id');
                    })->where('r.organization_id', $context->organizationId)->where('r.brand_id', $context->brandId)
                    ->whereExists(fn ($q) => $q->selectRaw('1')->from('snapshot_confirmations as sc')
                        ->whereColumn('sc.measurement_id', 'r.measurement_id')->whereColumn('sc.brand_id', 'r.brand_id')
                        ->whereColumn('sc.organization_id', 'r.organization_id'))
                    ->where(fn ($q) => $q->where('m.created_at', '<', $row->created_at)
                        ->orWhere(fn ($q) => $q->where('m.created_at', $row->created_at)->where('m.id', '<', $row->id)))
                    ->orderByDesc('m.created_at')->orderByDesc('m.id')->orderByDesc('r.revision')->value('r.id');
                $delta = null;
                if ($previous) {
                    try {
                        $comparison = app(ReportSections::class)->compare(app(VisionReportReader::class)->read($previous), $payload);
                        $delta = collect($comparison['rows'] ?? [])->firstWhere('label', 'Видимость')['delta'] ?? null;
                    } catch (\LogicException) {
                        // Do not compare inaccessible reports.
                    }
                }
                $reportMetrics[$report->measurement_id] = [
                    'visibility' => is_numeric($visibility) ? (float) $visibility : null,
                    'mentions' => is_numeric($mentions) ? (int) $mentions : null,
                    'delta' => $delta,
                ];
            } catch (\LogicException) {
                // A missing or inaccessible report has no verified metrics.
            }
        }

        $approval = DB::table('vision_question_approvals as approval')
            ->join('question_sets as questions', 'questions.id', '=', 'approval.question_set_id')
            ->where('approval.organization_id', $context->organizationId)->where('approval.brand_id', $context->brandId)
            ->where('questions.organization_id', $context->organizationId)->where('questions.brand_id', $context->brandId)
            ->where('approval.active', true)->first(['questions.size', 'approval.providers']);
        $providers = $approval ? json_decode($approval->providers, true) : [];

        return ['plan' => $approval ? ['questions' => (int) $approval->size, 'models' => is_array($providers) ? count($providers) : 0] : null,
            'data' => $rows->map(fn ($row) => [
                'id' => $row->id, 'status' => $row->status, 'created_at' => $row->created_at,
                'started_at' => $row->started_at, 'finished_at' => $row->finished_at,
                'confirmed' => in_array($row->id, $confirmed, true),
                'launch_source' => match ($launches[$row->id]->action ?? null) {
                    'vision.measurement.started' => 'manual',
                    'vision.measurement.repeated' => 'repeat',
                    default => null,
                },
                'visibility' => $reportMetrics[$row->id]['visibility'] ?? null,
                'mentions' => $reportMetrics[$row->id]['mentions'] ?? null,
                'visibility_delta' => $reportMetrics[$row->id]['delta'] ?? null,
                'answers' => ($answers[$row->id] ?? collect())->map(fn ($answer) => [
                    'question_n' => $answer->question_n, 'provider' => $answer->provider,
                    'requested_model' => $answer->requested_model, 'run_no' => $answer->run_no,
                    'status' => $answer->status, 'answer_text' => $answer->answer_text,
                    'error_code' => $answer->error_code,
                ])->values()->all(),
            ])->all(), 'page' => $page, 'last_page' => max(1, (int) ceil($count / 25)), 'total' => $count,
            'schedule' => app(MeasurementSchedule::class)->read($context)];
    }
}
