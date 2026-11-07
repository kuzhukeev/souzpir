<?php

namespace App\Vision\Competitors;

use App\Ai\CanonicalJson;
use App\Tenancy\TenantContext;
use App\Vision\Core\ReportSeal;
use App\Vision\Core\VisionReportReader;
use App\Vision\Core\WorkloadLimits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RecalculateMergedReport
{
    public function create(TenantContext $tenant): string
    {
        $scope = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId];
        $confirmed = DB::table('snapshot_confirmations')->where($scope)->pluck('measurement_id');
        $source = DB::table('vision_reports')->where($scope)->whereIn('measurement_id', $confirmed)->orderByDesc('created_at')->orderByDesc('id')->first();
        if (! $source) {
            throw ValidationException::withMessages(['source' => 'Нет подтверждённого отчёта для пересчёта.']);
        }
        try {
            $payload = app(VisionReportReader::class)->read($source->id);
            if (empty($payload['metrics']['slots']) || empty($payload['providers']) || empty($payload['questions']) || ! is_int($payload['runs'] ?? null) || $payload['runs'] < 1) {
                throw new \LogicException('Недостаточно сохранённых ответов.');
            }
            $aliases = DB::table('vision_competitor_aliases')->where($scope)->pluck('canonical', 'alias')->all();
            $payload['metrics'] = app(SnapshotView::class)->metrics($payload, $aliases);
            $payload['recalculated_from'] = $source->id;
            $revision = 1 + (int) DB::table('vision_reports')->where($scope)->where('measurement_id', $source->measurement_id)->max('revision');
            $id = (string) Str::ulid();
            $checksum = CanonicalJson::hash($payload);
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $size = DB::selectOne('select octet_length(cast(cast(? as jsonb) as text)) as bytes', [$encoded]);
            if (! $size || (int) $size->bytes > WorkloadLimits::dataBytes()) {
                throw new \LogicException('Отчёт превышает допустимый размер.');
            }
            DB::table('vision_reports')->insert(['id' => $id, ...$scope, 'measurement_id' => $source->measurement_id, 'revision' => $revision,
                'payload' => $encoded, 'checksum' => $checksum, 'snapshot_mac' => ReportSeal::mac($scope['organization_id'], $scope['brand_id'], $id, $source->measurement_id, $revision, $checksum),
                'created_at' => now(), 'updated_at' => now()]);

            return $id;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['source' => 'Не удалось пересчитать отчёт по сохранённым ответам.']);
        }
    }
}
