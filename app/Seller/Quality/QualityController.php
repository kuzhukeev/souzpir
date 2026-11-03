<?php

namespace App\Seller\Quality;

use App\Ai\Drivers\Rejected;
use App\Models\User;
use App\Seller\Evaluation\Judge;
use App\Seller\Evaluation\Supervisor;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class QualityController
{
    private function access(Request $request, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($callback) {
            Gate::forUser($user)->authorize('seller.knowledge.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($tenant);
        });
    }

    private function rows(TenantContext $tenant): Collection
    {
        $timezone = DB::table('brands')->where('organization_id', $tenant->organizationId)->where('id', $tenant->brandId)->value('timezone') ?: 'UTC';

        return DB::table('seller_evaluations')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(500)->get(['id', 'sim_run_id', 'conversation_id', 'scores', 'issues', 'average', 'comment', 'created_at'])
            ->map(function ($row) use ($tenant, $timezone): array {
                $feedback = $tenant->actorId ? DB::table('judge_calibrations')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('evaluation_id', $row->id)->where('user_id', $tenant->actorId)->first(['rating', 'comment']) : null;

                return ['id' => $row->id, 'source' => $row->sim_run_id ? 'simulation' : ($row->conversation_id ? 'dialogue' : 'unknown'),
                    'conversation_id' => $row->conversation_id, 'scores' => json_decode($row->scores, true) ?: [], 'issues' => json_decode($row->issues, true) ?: [],
                    'average' => (float) $row->average, 'comment' => $row->comment, 'created_at' => $row->created_at,
                    'feedback' => $feedback, 'day' => CarbonImmutable::parse($row->created_at)->setTimezone($timezone)->format('Y-m-d')];
            });
    }

    private function reports(TenantContext $tenant): Collection
    {
        return DB::table('seller_supervisor_reports')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
            ->orderByDesc('report_date')->orderByDesc('created_at')->limit(500)->get(['id', 'report_date', 'scores', 'issues', 'average', 'comment', 'created_at'])
            ->map(fn ($row) => ['id' => $row->id, 'source' => 'supervisor', 'conversation_id' => null, 'scores' => json_decode($row->scores, true) ?: [],
                'issues' => json_decode($row->issues, true) ?: [], 'average' => (float) $row->average, 'comment' => $row->comment,
                'created_at' => $row->created_at, 'day' => $row->report_date]);
    }

    public function index(Request $request): mixed
    {
        [$rows, $reports] = $this->access($request, fn (TenantContext $tenant) => [$this->rows($tenant), $this->reports($tenant)]);
        $group = fn ($data) => $data->groupBy('day')->map(function ($items, $day) {
            $issues = $items->flatMap(fn ($row) => $row['issues']);

            return ['day' => $day, 'count' => $items->count(), 'average' => round($items->avg('average'), 2),
                'issues' => $issues->count(), 'withIssues' => $items->filter(fn ($row) => count($row['issues']) > 0)->count(),
                'high' => $issues->where('severity', 'high')->count(), 'medium' => $issues->where('severity', 'medium')->count(),
                'topIssues' => $issues->countBy('type')->sortDesc()->take(3)->keys()->values()->all()];
        })->values();

        return Inertia::render('Seller/Quality/Index', ['days' => $group($rows), 'reportDays' => $group($reports)]);
    }

    public function day(Request $request, string $day): mixed
    {
        [$year, $month, $date] = array_map('intval', explode('-', $day));
        abort_unless(checkdate($month, $date, $year), 404);
        [$rows, $reports] = $this->access($request, fn (TenantContext $tenant) => [$this->rows($tenant)->where('day', $day)->values(), $this->reports($tenant)->where('day', $day)->values()]);

        return Inertia::render('Seller/Quality/Day', ['day' => $day, 'evaluations' => $rows, 'reports' => $reports]);
    }

    public function evaluateSimulation(Request $request): mixed
    {
        $day = $this->access($request, function (TenantContext $tenant): string {
            if (config('runtime.ai_driver') !== 'fake') {
                throw ValidationException::withMessages(['evaluation' => 'Тестовая оценка доступна только с fake-драйвером. Оценка реальных диалогов пока недоступна.']);
            }
            $run = DB::table('seller_sim_runs as r')
                ->where('r.organization_id', $tenant->organizationId)->where('r.brand_id', $tenant->brandId)
                ->where('r.status', 'completed')->where('r.turn_count', '>', 0)
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('seller_evaluations as e')->whereColumn('e.sim_run_id', 'r.id')->whereColumn('e.organization_id', 'r.organization_id')->whereColumn('e.brand_id', 'r.brand_id'))
                ->orderByDesc('r.created_at')->orderByDesc('r.id')->value('r.id');
            if (! $run) {
                throw ValidationException::withMessages(['evaluation' => 'Нет завершённых симуляций без оценки для текущего бренда.']);
            }
            try {
                app(Judge::class)->evaluate($tenant, $run);
            } catch (Rejected $exception) {
                throw ValidationException::withMessages(['evaluation' => 'Для этой симуляции нет подготовленного тестового ответа Judge.']);
            }
            $timezone = DB::table('brands')->where('organization_id', $tenant->organizationId)->where('id', $tenant->brandId)->value('timezone') ?: 'UTC';

            return now($timezone)->toDateString();
        });

        return redirect()->route('seller.quality.day', ['day' => $day]);
    }

    public function collectToday(Request $request): mixed
    {
        $day = $this->access($request, function (TenantContext $tenant): string {
            $timezone = DB::table('brands')->where('organization_id', $tenant->organizationId)->where('id', $tenant->brandId)->value('timezone') ?: 'UTC';
            $day = now($timezone)->toDateString();
            $evaluations = $this->rows($tenant)->where('day', $day)->where('source', 'dialogue')->filter(fn ($row) => collect(Supervisor::SCORES)->every(fn ($key) => isset($row['scores'][$key]) && is_int($row['scores'][$key]) && $row['scores'][$key] >= 1 && $row['scores'][$key] <= 5));
            if ($evaluations->isEmpty()) {
                throw ValidationException::withMessages(['report' => 'За сегодня нет полных оценок диалогов для доклада.']);
            }
            $result = [];
            foreach (Supervisor::SCORES as $key) {
                $result[$key] = (int) round($evaluations->avg(fn ($row) => $row['scores'][$key]));
            }
            $result['issues'] = $evaluations->flatMap(fn ($row) => $row['issues'])->values()->all();
            $result['comment'] = 'Сводка по сохранённым оценкам диалогов за день.';
            $turnCount = max(1, collect($result['issues'])->max('turn') ?? 1);
            app(SupervisorReportWriter::class)->write($tenant, $day, $result, $turnCount);

            return $day;
        });

        return redirect()->route('seller.quality.day', ['day' => $day]);
    }

    public function recommendation(Request $request, string $day): mixed
    {
        [$year, $month, $date] = array_map('intval', explode('-', $day));
        abort_unless(checkdate($month, $date, $year), 404);
        $input = $request->validate(['fix' => ['required', 'string', 'max:3000'], 'completed' => ['required', 'boolean']]);
        $this->access($request, function (TenantContext $tenant) use ($day, $input): void {
            DB::transaction(function () use ($tenant, $day, $input): void {
                $reports = DB::table('seller_supervisor_reports')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('report_date', $day)->get(['id', 'issues']);
                $found = false;
                foreach ($reports as $report) {
                    $issues = json_decode($report->issues, true) ?: [];
                    $changed = false;
                    foreach ($issues as &$issue) {
                        if (($issue['fix'] ?? null) === $input['fix']) {
                            $issue['completed'] = $input['completed'];
                            $changed = $found = true;
                        }
                    }
                    unset($issue);
                    if ($changed) {
                        DB::table('seller_supervisor_reports')->where('id', $report->id)->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->update(['issues' => json_encode($issues, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                    }
                }
                abort_unless($found, 404);
                app(AuditWriter::class)->record($tenant, new AuditEntry('seller.supervisor.recommendation.updated', 'seller_supervisor_report', $day, after: ['completed' => $input['completed']]));
            });
        });

        return back();
    }

    public function disagree(Request $request, string $evaluation): mixed
    {
        $input = $request->validate(['rating' => ['required', 'integer', 'min:1', 'max:5'], 'comment' => ['required', 'string', 'max:2000']]);
        $this->access($request, function (TenantContext $tenant) use ($request, $evaluation, $input): void {
            Gate::authorize('seller.settings.write');
            DB::table('seller_evaluations')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('id', $evaluation)->first() ?? abort(404);
            $where = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId, 'evaluation_id' => $evaluation, 'user_id' => $request->user()->id];
            $old = DB::table('judge_calibrations')->where($where)->first();
            $id = $old?->id ?? (string) Str::ulid();
            DB::table('judge_calibrations')->updateOrInsert($where, ['id' => $id, 'rating' => $input['rating'], 'comment' => $input['comment'], 'created_at' => $old?->created_at ?? now(), 'updated_at' => now()]);
            app(AuditWriter::class)->record($tenant, new AuditEntry('seller.judge.calibrated', 'judge_calibration', $id));
        });

        return back();
    }
}
