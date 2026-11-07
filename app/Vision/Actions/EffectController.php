<?php

namespace App\Vision\Actions;

use App\Tenancy\TenantContext;
use App\Vision\Measurements\MeasurementList;
use App\Vision\Settings\Trends;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final class EffectController
{
    public function index(Request $request): mixed
    {
        return app(MeasurementList::class)->withinCurrent($request, function (TenantContext $tenant) use ($request): mixed {
            Gate::authorize('vision.report.read');
            $rows = app(Trends::class)->read($tenant);
            $actions = DB::table('actions')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->whereNotNull('completed_at')->orderByDesc('completed_at')->limit(100)->get(['id', 'title', 'completed_at']);
            $items = app(EffectProjection::class)->make($actions->all(), $rows);

            $logs = DB::table('audit_logs')->where('organization_id', $tenant->organizationId)
                ->where('brand_id', $tenant->brandId)->where('action', 'vision.action.updated')
                ->where('subject_type', 'vision_action')->whereIn('subject_id', $actions->pluck('id'))
                ->orderBy('created_at')->get(['subject_id', 'actor_id', 'before', 'after']);
            $authors = [];
            foreach ($logs as $log) {
                $before = json_decode($log->before ?? 'null', true);
                $after = json_decode($log->after ?? 'null', true);
                if (($before['status'] ?? null) === 'open' && ($after['status'] ?? null) === 'awaiting_measurement') {
                    $authors[$log->subject_id] = $log->actor_id;
                }
            }
            $names = DB::table('users')->whereIn('id', array_filter(array_values($authors)))->pluck('name', 'id');
            $items = array_map(function (array $item) use ($authors, $names): array {
                $item['completed_by_name'] = $names[$authors[$item['id']] ?? ''] ?? null;

                return $item;
            }, $items);

            if ($request->expectsJson()) {
                $links = DB::table('vision_action_recommendation_links')->where('organization_id', $tenant->organizationId)
                    ->where('brand_id', $tenant->brandId)->get(['action_id', 'metadata']);
                $recommendations = $links->mapWithKeys(fn ($link) => [$link->action_id => json_decode($link->metadata, true) ?: []]);

                $nextMeasurement = DB::table('monitoring_schedules')->where('organization_id', $tenant->organizationId)
                    ->where('brand_id', $tenant->brandId)->where('enabled', true)->where('next_run_at', '>', now())->value('next_run_at');

                return response()->json(['items' => $items, 'recommendations' => $recommendations, 'next_measurement_at' => $nextMeasurement]);
            }

            return Inertia::render('Vision/Actions/Effect', ['items' => $items]);
        }, false);
    }
}
