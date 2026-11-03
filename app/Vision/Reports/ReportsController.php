<?php

namespace App\Vision\Reports;

use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use App\Vision\Core\ReportPreparation;
use App\Vision\Measurements\MeasurementList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use LogicException;

final class ReportsController
{
    public function index(Request $request): mixed
    {
        return app(MeasurementList::class)->withinCurrent($request, function (TenantContext $context, string $revision): mixed {
            Gate::authorize('vision.report.read');

            if (session()->has('publication_url')) {
                Inertia::encryptHistory();
            }

            return Inertia::render('Vision/Reports/Index', ['reportBrandRevision' => $revision, 'status' => session('status'), 'createdUrl' => session('publication_url'), 'ttlDays' => config('features.E8-05C.defaults.publication_ttl_days')]);
        }, false);
    }

    public function data(Request $request): mixed
    {
        return app(MeasurementList::class)->withinCurrent($request, function (TenantContext $context, string $revision) use ($request): mixed {
            Gate::authorize('vision.report.read');
            $id = $request->query('report');
            abort_unless($id === null || is_string($id), 404);
            try {
                $data = app(ReportData::class)->read($context, $id);
            } catch (LogicException) {
                abort(409, 'Отчёт недоступен.');
            }

            return response()->json(['revision' => $revision, ...$data]);
        });
    }

    public function create(Request $request): mixed
    {
        $data = $request->validate(['measurement_id' => ['required', 'ulid']]);
        app(MeasurementList::class)->withinCurrent($request, function (TenantContext $context) use ($data): void {
            Gate::authorize('vision.report.read');
            Gate::authorize('vision.measure.run');
            $confirmation = DB::table('snapshot_confirmations')->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->where('measurement_id', $data['measurement_id'])->first(['id']);
            if (! $confirmation) {
                throw ValidationException::withMessages(['measurement_id' => 'Сначала подтвердите завершённый замер.']);
            }
        });
        try {
            $id = DB::transaction(function () use ($data): string {
                $id = app(ReportPreparation::class)->snapshot($data['measurement_id']);
                app(AuditWriter::class)->record(app(TenantContext::class), new AuditEntry('vision.report.created', 'vision_report', $id,
                    after: ['measurement_id' => $data['measurement_id']]));

                return $id;
            });
        } catch (LogicException) {
            throw ValidationException::withMessages(['measurement_id' => 'Не удалось подготовить отчёт из подтверждённого замера.']);
        }

        return redirect()->route('vision.reports.index', ['report' => $id])->with('status', 'Внутренний отчёт подготовлен.');
    }
}
