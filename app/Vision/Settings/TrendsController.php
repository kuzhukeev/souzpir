<?php

namespace App\Vision\Settings;

use App\Tenancy\TenantContext;
use App\Vision\Measurements\MeasurementList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use LogicException;

final class TrendsController
{
    public function index(Request $request): mixed
    {
        return app(MeasurementList::class)->withinCurrent($request, function (TenantContext $tenant): mixed {
            Gate::authorize('vision.report.read');
            try {
                $rows = app(Trends::class)->read($tenant);
                $error = false;
            } catch (LogicException) {
                $rows = [];
                $error = true;
            }

            return Inertia::render('Vision/Settings/Trends', compact('rows', 'error'));
        }, false);
    }
}
