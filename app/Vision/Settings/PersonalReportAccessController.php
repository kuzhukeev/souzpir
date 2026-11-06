<?php

namespace App\Vision\Settings;

use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use App\Vision\Measurements\MeasurementList;
use App\Vision\Publications\PublicationData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;

final class PersonalReportAccessController
{
    public function create(Request $request): RedirectResponse
    {
        $input = $request->validate(['report_id' => ['required', 'ulid'], 'name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:254']]);
        $token = bin2hex(random_bytes(32));
        $brandId = null;
        app(MeasurementList::class)->withinCurrent($request, function (TenantContext $tenant) use ($input, $token, &$brandId): void {
            $brandId = $tenant->brandId;
            Gate::authorize('vision.report.read');
            Gate::authorize('vision.settings.write');
            $report = DB::table('vision_reports')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->where('id', $input['report_id'])->whereIn('measurement_id', DB::table('snapshot_confirmations')->where('organization_id', $tenant->organizationId)
                ->where('brand_id', $tenant->brandId)->select('measurement_id'))->first(['id', 'measurement_id', 'created_at']);
            abort_unless($report, 404);
            try {
                $payload = app(PublicationData::class)->fromReport($report->id, null, [], $tenant, $report);
            } catch (LogicException) {
                abort(409, 'Отчёт недоступен.');
            }
            $id = (string) Str::ulid();
            DB::transaction(function () use ($tenant, $input, $token, $report, $payload, $id): void {
                DB::table('report_publications')->insert(['id' => $id, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
                    'report_id' => $report->id, 'created_by' => $tenant->actorId, 'token_hash' => hash('sha256', $token),
                    'public_payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'expires_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now()]);
                DB::table('vision_personal_report_access')->insert(['id' => (string) Str::ulid(), 'organization_id' => $tenant->organizationId,
                    'brand_id' => $tenant->brandId, 'publication_id' => $id, 'name' => trim($input['name']), 'email' => trim($input['email']),
                    'created_at' => now(), 'updated_at' => now()]);
                app(AuditWriter::class)->record($tenant, new AuditEntry('vision.report.personal_access.created', 'report_publication', $id));
            });
        });

        return redirect('/vision/settings')->with('personal_report_brand', $brandId)->with('personal_report_url', route('vision.publications.public', $token))
            ->with('status', 'Ссылка создана. Скопируйте её сейчас: повторно она не показывается.');
    }

    public function revoke(Request $request, string $access): RedirectResponse
    {
        app(MeasurementList::class)->withinCurrent($request, function (TenantContext $tenant) use ($access): void {
            Gate::authorize('vision.report.read');
            Gate::authorize('vision.settings.write');
            DB::transaction(function () use ($tenant, $access): void {
                $row = DB::table('vision_personal_report_access')->where('id', $access)->where('organization_id', $tenant->organizationId)
                    ->where('brand_id', $tenant->brandId)->first(['publication_id']);
                abort_unless($row, 404);
                $publication = DB::table('report_publications')->where('id', $row->publication_id)->where('organization_id', $tenant->organizationId)
                    ->where('brand_id', $tenant->brandId)->first(['revoked_at']);
                abort_unless($publication, 404);
                if ($publication->revoked_at === null) {
                    DB::table('report_publications')->where('id', $row->publication_id)->update(['revoked_at' => now(), 'updated_at' => now()]);
                    app(AuditWriter::class)->record($tenant, new AuditEntry('vision.report.personal_access.revoked', 'report_publication', $row->publication_id));
                }
            });
        });

        return redirect('/vision/settings')->with('status', 'Доступ отозван.');
    }
}
