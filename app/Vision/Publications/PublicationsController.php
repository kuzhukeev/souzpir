<?php

namespace App\Vision\Publications;

use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use App\Vision\Measurements\MeasurementList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

final class PublicationsController
{
    public function index(Request $request): Response
    {
        return app(MeasurementList::class)->withinCurrent($request, function (TenantContext $context, string $revision): Response {
            Gate::authorize('vision.report.read');
            $reports = DB::table('vision_reports')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)
                ->whereIn('measurement_id', DB::table('snapshot_confirmations')->where('organization_id', $context->organizationId)
                    ->where('brand_id', $context->brandId)->select('measurement_id'))
                ->orderByDesc('created_at')->get(['id', 'revision', 'created_at']);
            $publications = DB::table('report_publications')->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->orderByDesc('created_at')
                ->get(['id', 'report_id', 'expires_at', 'revoked_at', 'created_at']);

            if (session()->has('publication_url')) {
                Inertia::encryptHistory();
            }

            return Inertia::render('Vision/Publications/Index', [
                'reports' => $reports, 'publications' => $publications,
                'ttlDays' => config('features.E8-05C.defaults.publication_ttl_days'),
                'reportBrandRevision' => $revision,
                'canPublish' => Gate::allows('vision.measure.run'),
                'createdUrl' => session('publication_url'), 'status' => session('status'),
            ]);
        }, false);
    }

    public function create(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'report_id' => ['required', 'ulid'],
            'ttl_days' => ['required', 'integer', Rule::in(config('features.E8-05C.defaults.publication_ttl_days'))],
        ]);
        $token = bin2hex(random_bytes(32));
        app(MeasurementList::class)->withinCurrent($request, function (TenantContext $context) use ($data, $token): void {
            Gate::authorize('vision.report.read');
            Gate::authorize('vision.measure.run');
            $report = DB::table('vision_reports')->where('id', $data['report_id'])->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->whereIn('measurement_id', DB::table('snapshot_confirmations')
                ->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->select('measurement_id'))->first(['id']);
            abort_unless($report, 404);
            try {
                $public = app(PublicationData::class)->fromReport($report->id);
            } catch (LogicException) {
                abort(409, 'Отчёт недоступен.');
            }
            $id = (string) Str::ulid();
            DB::table('report_publications')->insert([
                'id' => $id, 'organization_id' => $context->organizationId, 'brand_id' => $context->brandId,
                'report_id' => $report->id, 'created_by' => $context->actorId,
                'token_hash' => hash('sha256', $token), 'public_payload' => json_encode($public, JSON_THROW_ON_ERROR),
                'expires_at' => now()->addDays($data['ttl_days']), 'created_at' => now(), 'updated_at' => now(),
            ]);
            app(AuditWriter::class)->record($context, new AuditEntry('vision.report.published', 'report_publication', $id,
                after: ['report_id' => $report->id, 'ttl_days' => $data['ttl_days']]));
        });

        return redirect()->route('vision.publications.index')->with('publication_url', route('vision.publications.public', $token))
            ->with('status', 'Ссылка создана. Сохраните её сейчас: повторно показать токен нельзя.');
    }

    public function revoke(Request $request, string $publication): RedirectResponse
    {
        app(MeasurementList::class)->withinCurrent($request, function (TenantContext $context) use ($publication): void {
            Gate::authorize('vision.report.read');
            Gate::authorize('vision.measure.run');
            $row = DB::table('report_publications')->where('id', $publication)->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->lockForUpdate()->first(['id', 'revoked_at']);
            abort_unless($row, 404);
            if ($row->revoked_at === null) {
                DB::table('report_publications')->where('id', $row->id)->update(['revoked_at' => now(), 'updated_at' => now()]);
                app(AuditWriter::class)->record($context, new AuditEntry('vision.report.publication.revoked', 'report_publication', $row->id));
            }
        });

        return redirect()->route('vision.publications.index')->with('status', 'Ссылка отозвана.');
    }
}
