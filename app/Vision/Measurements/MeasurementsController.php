<?php

namespace App\Vision\Measurements;

use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class MeasurementsController
{
    public function index(Request $request): mixed
    {
        return app(MeasurementList::class)->withinCurrent($request,
            fn (TenantContext $context, string $revision) => Inertia::render('Vision/Measurements/Index', ['measurementBrandRevision' => $revision]), false);
    }

    public function data(Request $request): mixed
    {
        $page = filter_var($request->query('page', 1), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
        abort_if($page === false, 404);

        return app(MeasurementList::class)->withinCurrent($request,
            fn (TenantContext $context, string $revision) => response()->json(['revision' => $revision, ...app(MeasurementList::class)->read($context, $page)]));
    }

    public function revision(Request $request): mixed
    {
        return app(MeasurementList::class)->withinCurrent($request,
            fn (TenantContext $context, string $revision) => response()->json(['revision' => $revision]));
    }
}
