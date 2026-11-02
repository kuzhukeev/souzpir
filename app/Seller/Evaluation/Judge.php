<?php

namespace App\Seller\Evaluation;

use App\Ai\Drivers\FakeDriver;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

final class Judge
{
    public function evaluate(TenantContext $tenant, string $runId): string
    {
        if (config('runtime.ai_driver') !== 'fake') {
            throw new LogicException('Judge доступен только с fake-драйвером.');
        }
        $run = DB::table('seller_sim_runs')->where('organization_id', $tenant->requireOrganizationId())
            ->where('brand_id', $tenant->brandId)->where('id', $runId)->first() ?? abort(404);
        if ($run->status !== 'completed' || $run->turn_count < 1) {
            throw new LogicException('Оценить можно только завершённый прогон.');
        }
        $existing = DB::table('seller_evaluations')->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->where('sim_run_id', $runId)->value('id');
        if ($existing) {
            return $existing;
        }
        $persona = DB::table('seller_personas')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
            ->where('id', $run->persona_id)->first() ?? abort(404);
        $turns = json_decode($run->turns, true, 512, JSON_THROW_ON_ERROR);
        $payload = json_decode($persona->payload, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($turns) || ! is_array($payload)) {
            throw new LogicException('Некорректная трасса симуляции.');
        }
        $body = $this->request($turns, $payload);
        $result = app(FakeDriver::class)->complete($body);
        $decoded = json_decode($result['content'] ?? '', true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new LogicException('Некорректный ответ Judge.');
        }

        return app(FakeSimulation::class)->judge($tenant, $runId, $decoded, app(Supervisor::class));
    }

    public function request(array $turns, array $persona): array
    {
        return ['model' => 'fake-judge', 'temperature' => 0, 'messages' => [
            ['role' => 'system', 'content' => 'Оцени диалог по шкалам naturalness, goal, facts, objections, brevity от 1 до 5. Верни JSON с comment и issues; объясняй проблемы.'],
            ['role' => 'user', 'content' => json_encode(['dialog' => app(Supervisor::class)->transcript($turns), 'expect' => $persona['expect'] ?? []], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
        ]];
    }
}
