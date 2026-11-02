<?php

namespace App\Seller\Quality;

use App\Seller\Evaluation\Supervisor;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class SupervisorReportWriter
{
    public function write(TenantContext $tenant, string $date, array $result, int $turnCount): string
    {
        $parts = explode('-', $date);
        if (count($parts) !== 3 || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || ! checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]) || $turnCount < 1) {
            throw new InvalidArgumentException('Неверные параметры доклада.');
        }
        $brand = DB::table('brands')->where('id', $tenant->brandId)->where('organization_id', $tenant->organizationId)->exists();
        if (! $brand || ! $tenant->organizationId || ! $tenant->brandId) {
            throw new InvalidArgumentException('Бренд недоступен.');
        }
        $normal = app(Supervisor::class)->normalize($result, $turnCount);
        $id = (string) Str::ulid();
        DB::transaction(function () use ($tenant, $date, $normal, $id): void {
            DB::table('seller_supervisor_reports')->insert(['id' => $id, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
                'report_date' => $date, 'scores' => json_encode($normal['scores'], JSON_THROW_ON_ERROR), 'issues' => json_encode($normal['issues'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'average' => $normal['average'], 'comment' => $normal['comment'], 'created_at' => now(), 'updated_at' => now()]);
            app(AuditWriter::class)->record($tenant, new AuditEntry('seller.supervisor.report.created', 'seller_supervisor_report', $id, after: ['report_date' => $date]));
        });

        return $id;
    }
}
