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
        return app(MeasurementList::class)->withinCurrent($request, function (TenantContext $tenant): mixed {
            Gate::authorize('vision.report.read');
            $rows = app(Trends::class)->read($tenant);
            $actions = DB::table('actions')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->whereNotNull('completed_at')->orderByDesc('completed_at')->limit(100)->get(['id', 'title', 'completed_at']);
            $items = app(EffectProjection::class)->make($actions->all(), $rows);

            return Inertia::render('Vision/Actions/Effect', ['items' => $items]);
        }, false);
    }
}
