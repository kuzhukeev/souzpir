<?php

namespace App\Vision\Publications;

use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use App\Vision\Core\VisionReportReader;
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
        return app(MeasurementList::class)->withinCurrent($request, function (TenantContext $context, string $revision) use ($request): Response {
            Gate::authorize('vision.report.read');
            $reports = DB::table('vision_reports')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)
                ->whereIn('measurement_id', DB::table('snapshot_confirmations')->where('organization_id', $context->organizationId)
                    ->where('brand_id', $context->brandId)->select('measurement_id'))
                ->orderByDesc('created_at')->get(['id', 'revision', 'created_at']);
            $publications = DB::table('report_publications')->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->orderByDesc('created_at')
                ->get(['id', 'report_id', 'expires_at', 'revoked_at', 'created_at']);

            $review = null;
            if ($request->filled('review_report')) {
                Gate::authorize('vision.measure.run');
                $input = $request->validate(['review_report' => ['required', 'ulid']]);
                abort_unless($reports->contains('id', $input['review_report']), 404);
                try {
                    $payload = app(VisionReportReader::class)->read($input['review_report']);
                    $review = ['report_id' => $input['review_report'], 'candidates' => app(PublicationData::class)->candidatesFromPayload($payload)];
                } catch (LogicException) {
                    abort(404);
                }
            }

            if (session()->has('publication_url')) {
                Inertia::encryptHistory();
            }

            return Inertia::render('Vision/Publications/Index', [
                'reports' => $reports, 'publications' => $publications, 'review' => $review,
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
            'return_to' => ['nullable', Rule::in(['report'])],
            'allow_pdf' => ['sometimes', 'boolean'],
            'confirmed_actions' => ['sometimes', 'array', 'max:3'],
            'confirmed_actions.*' => ['required', 'string', 'regex:/^action:\d+$/D'],
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
                $audit = DB::table('audit_runs')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)
                    ->orderByDesc('created_at')->orderByDesc('id')->value('result');
                $siteAudit = is_string($audit) ? json_decode($audit, true) : null;
                $public = app(PublicationData::class)->fromReport($report->id, is_array($siteAudit) ? $siteAudit : null,
                    array_key_exists('confirmed_actions', $data) ? $data['confirmed_actions'] : null);
                $public['allow_pdf'] = (bool) ($data['allow_pdf'] ?? false);
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
                after: ['report_id' => $report->id, 'ttl_days' => $data['ttl_days'], 'approved_actions' => count($data['confirmed_actions'] ?? [])]));
        });

        $destination = ($data['return_to'] ?? null) === 'report' ? route('vision.reports.index', ['report' => $data['report_id']]) : route('vision.publications.index');

        return redirect($destination)->with('publication_url', route('vision.publications.public', $token))
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
