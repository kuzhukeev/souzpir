<?php

namespace App\Seller\Quality;

use App\Models\User;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
            ->map(fn ($row) => ['id' => $row->id, 'source' => $row->sim_run_id ? 'simulation' : ($row->conversation_id ? 'dialogue' : 'unknown'),
                'conversation_id' => $row->conversation_id, 'scores' => json_decode($row->scores, true) ?: [], 'issues' => json_decode($row->issues, true) ?: [],
                'average' => (float) $row->average, 'comment' => $row->comment, 'created_at' => $row->created_at,
                'day' => CarbonImmutable::parse($row->created_at)->setTimezone($timezone)->format('Y-m-d')]);
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
        $group = fn ($data) => $data->groupBy('day')->map(fn ($items, $day) => ['day' => $day, 'count' => $items->count(), 'average' => round($items->avg('average'), 2), 'issues' => $items->sum(fn ($row) => count($row['issues']))])->values();

        return Inertia::render('Seller/Quality/Index', ['days' => $group($rows), 'reportDays' => $group($reports)]);
    }

    public function day(Request $request, string $day): mixed
    {
        [$year, $month, $date] = array_map('intval', explode('-', $day));
        abort_unless(checkdate($month, $date, $year), 404);
        [$rows, $reports] = $this->access($request, fn (TenantContext $tenant) => [$this->rows($tenant)->where('day', $day)->values(), $this->reports($tenant)->where('day', $day)->values()]);

        return Inertia::render('Seller/Quality/Day', ['day' => $day, 'evaluations' => $rows, 'reports' => $reports]);
    }
}
