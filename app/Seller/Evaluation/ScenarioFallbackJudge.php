<?php

namespace App\Seller\Evaluation;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Test-only baseline, not a quality assessment of the dialogue. */
final class ScenarioFallbackJudge
{
    public const COMMENT = 'Тестовая оценка: фиксированное значение, качество диалога не оценивалось.';

    public function evaluate(TenantContext $tenant, string $runId): string
    {
        if (config('runtime.ai_driver', 'fake') !== 'fake') {
            throw new LogicException('Fake judge unavailable.');
        }
        $run = DB::table('seller_sim_runs')->where('organization_id', $tenant->requireOrganizationId())
            ->where('brand_id', $tenant->brandId)->where('id', $runId)->first() ?? abort(404);
        $turns = json_decode($run->turns, true, 512, JSON_THROW_ON_ERROR);
        if ($run->status !== 'completed' || ! is_array($turns) || count($turns) !== (int) $run->turn_count || $turns === []) {
            throw new LogicException('Invalid simulation.');
        }
        foreach ($turns as $turn) {
            if (! is_array($turn) || ! is_string($turn['agent'] ?? null) || trim($turn['agent']) === '') {
                throw new LogicException('Invalid simulation.');
            }
        }
        $existing = DB::table('seller_evaluations')->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->where('sim_run_id', $runId)->value('id');
        if ($existing) {
            return $existing;
        }

        return app(FakeSimulation::class)->judge($tenant, $runId, [...array_fill_keys(Supervisor::SCORES, 3),
            'comment' => self::COMMENT, 'issues' => []], app(Supervisor::class));
    }
}
