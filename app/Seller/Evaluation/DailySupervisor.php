<?php

namespace App\Seller\Evaluation;

use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DailySupervisor
{
    public function run(?CarbonImmutable $day = null): int
    {
        $day = ($day ?? CarbonImmutable::now('Europe/Moscow')->subDay())->startOfDay();
        $from = $day->utc();
        $to = $day->addDay()->utc();
        $rows = DB::table('seller_evaluations')->whereNotNull('sim_run_id')->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->where(fn ($query) => $query->whereNull('comment')->orWhere('comment', '!=', ScenarioFallbackJudge::COMMENT))
            ->orderBy('id')->get(['id', 'organization_id', 'brand_id', 'issues', 'average']);
        $created = 0;
        foreach ($rows->groupBy(fn ($row) => $row->organization_id.':'.$row->brand_id) as $group) {
            $first = $group->first();
            $tenant = new TenantContext($first->organization_id, $first->brand_id, source: 'job');
            $marker = 'supervisor:'.$day->format('Y-m-d');
            if (DB::table('seller_evaluations')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->whereNull('sim_run_id')->whereNull('conversation_id')->where('comment', $marker)->exists()) {
                continue;
            }
            $evaluations = $group->map(fn ($row) => ['id' => $row->id, 'issues' => json_decode($row->issues, true, 512, JSON_THROW_ON_ERROR)])->all();
            $summary = app(Supervisor::class)->aggregate($evaluations);
            $id = (string) Str::ulid();
            DB::transaction(function () use ($tenant, $marker, $summary, $group, $id): void {
                DB::table('seller_evaluations')->insert(['id' => $id, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
                    'sim_run_id' => null, 'conversation_id' => null,
                    'scores' => json_encode(['type' => 'supervisor', 'dialogs' => $summary['dialogs'], 'by_layer' => $summary['by_layer']], JSON_THROW_ON_ERROR),
                    'issues' => json_encode($summary['groups'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'average' => round($group->avg('average'), 2), 'comment' => $marker, 'created_at' => now(), 'updated_at' => now()]);
                app(AuditWriter::class)->record($tenant, new AuditEntry('seller.supervisor.completed', 'seller_evaluation', $id, after: ['dialogs' => $summary['dialogs']]));
            });
            $created++;
        }

        return $created;
    }
}
