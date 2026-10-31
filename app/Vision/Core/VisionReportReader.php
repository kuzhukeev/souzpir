<?php

namespace App\Vision\Core;

use App\Ai\CanonicalJson;
use App\Tenancy\TenantContext;
use App\Vision\Evidence\Contracts\KnowledgeGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/** Sole domain entry point for reading persisted internal report snapshots. */
final class VisionReportReader
{
    public function __construct(private Tenant $tenant) {}

    public function read(string $reportId): array
    {
        try {
            if (! Str::isUlid($reportId)) {
                throw new LogicException;
            }
            $tenant = $this->tenant->current();

            return DB::transaction(function () use ($tenant, $reportId): array {
                $reference = DB::table('vision_reports')->where($tenant)->where('id', $reportId)->first(['measurement_id']);
                if (! $reference) {
                    throw new LogicException;
                }
                MeasurementLock::acquire($reference->measurement_id);
                $measurement = DB::table('measurements')->where($tenant)->where('id', $reference->measurement_id)->first();
                if (! $measurement || ! is_string($measurement->profile_snapshot) || ! is_string($measurement->configuration)) {
                    throw new LogicException;
                }
                $profile = json_decode($measurement->profile_snapshot, true, 64, JSON_THROW_ON_ERROR);
                $configuration = json_decode($measurement->configuration, true, 64, JSON_THROW_ON_ERROR);
                if (! is_array($profile) || ! is_array($configuration)) {
                    throw new LogicException;
                }
                // The gate takes the tenant, knowledge and measurement locks in order.
                app(KnowledgeGate::class)->assertAllowed(app(TenantContext::class), $measurement->id, $profile);
                $report = DB::table('vision_reports')->where($tenant)->where('id', $reportId)
                    ->where('measurement_id', $measurement->id)->sharedLock()->first();
                if (! $report || ! is_string($report->payload) || ! is_string($report->checksum) || ! is_string($report->snapshot_mac)
                    || ! hash_equals(ReportSeal::mac($tenant['organization_id'], $tenant['brand_id'], $reportId, $measurement->id, (int) $report->revision, $report->checksum), $report->snapshot_mac)) {
                    throw new LogicException;
                }
                $payload = json_decode($report->payload, true, 128, JSON_THROW_ON_ERROR);
                if (! is_array($payload) || ($payload['measurement_id'] ?? null) !== $measurement->id
                    || ! hash_equals($report->checksum, CanonicalJson::hash($payload))
                    || ! is_array($payload['questions'] ?? null) || ! is_string($payload['question_set_checksum'] ?? null)
                    || ! hash_equals($payload['question_set_checksum'], CanonicalJson::hash($payload['questions']))
                    || ! hash_equals((string) ($configuration['question_set_checksum'] ?? ''), $payload['question_set_checksum'])) {
                    throw new LogicException;
                }

                return $payload;
            });
        } catch (Throwable) {
            // Neither the frozen profile nor another tenant's report reaches callers or logs.
            throw new LogicException('Vision report is unavailable.');
        }
    }
}
