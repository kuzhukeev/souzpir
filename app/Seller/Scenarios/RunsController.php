<?php

namespace App\Seller\Scenarios;

use App\Models\User;
use App\Seller\Evaluation\Jobs\SimulateFakeJob;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class RunsController
{
    private function access(Request $request, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($callback) {
            Gate::forUser($user)->authorize('seller.knowledge.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($tenant);
        });
    }

    public function index(Request $request): mixed
    {
        $data = $this->access($request, function (TenantContext $tenant): array {
            $personas = DB::table('seller_personas')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->orderBy('name')->get(['id', 'name']);
            $runs = DB::table('seller_sim_runs')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->orderByDesc('created_at')->limit(100)->get(['id', 'persona_id', 'status', 'turn_count', 'turns', 'metrics', 'created_at']);
            $evaluations = DB::table('seller_evaluations')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->whereIn('sim_run_id', $runs->pluck('id'))->get(['sim_run_id', 'scores', 'issues', 'average'])->keyBy('sim_run_id');
            $release = DB::table('brands as b')->join('knowledge_releases as r', function ($join): void {
                $join->on('r.id', '=', 'b.active_knowledge_release_id')->on('r.organization_id', '=', 'b.organization_id')->on('r.brand_id', '=', 'b.id');
            })->where('b.organization_id', $tenant->organizationId)->where('b.id', $tenant->brandId)->where('r.status', 'published')->value('r.id');

            $releaseIds = $runs->map(fn ($row) => data_get(json_decode($row->metrics, true), 'knowledge_release_id'))->filter()->unique()->all();
            $releaseStatuses = DB::table('knowledge_releases')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->whereIn('id', $releaseIds)->pluck('status', 'id');

            $groups = DB::table('seller_scenario_run_groups')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->orderByDesc('created_at')->limit(50)->get();
            $groupRunIds = $groups->flatMap(fn ($group) => collect(json_decode($group->items, true) ?: [])->pluck('run_id'))->filter()->all();
            $groupEvaluations = DB::table('seller_evaluations')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->whereIn('sim_run_id', $groupRunIds)->pluck('average', 'sim_run_id');
            $groupData = $groups->map(function ($group) use ($groupEvaluations): array {
                $items = collect(json_decode($group->items, true) ?: [])->map(function (array $item) use ($groupEvaluations): array {
                    $average = $groupEvaluations[$item['run_id'] ?? ''] ?? null;

                    return ['name' => $item['name'], 'status' => $item['status'], 'run_id' => $item['run_id'] ?? null, 'average' => $average];
                });

                return ['id' => $group->id, 'status' => $group->status, 'created_at' => $group->created_at, 'items' => $items->all(), 'average' => $items->whereNotNull('average')->avg('average'),
                    'passed' => $items->filter(fn (array $item) => $item['average'] !== null && (float) $item['average'] >= 4)->count(),
                    'failed' => $items->filter(fn (array $item) => $item['average'] !== null && (float) $item['average'] < 4)->count()];
            });

            return ['groups' => $groupData, 'scenarios' => $personas, 'publishedAvailable' => $release !== null, 'runtimeAvailable' => config('runtime.ai_driver', 'fake') === 'fake' && config('features.E3-06D.defaults.runtime_enabled', true) !== false,
                'runs' => $runs->map(function ($run) use ($evaluations, $personas, $releaseStatuses): array {
                    $evaluation = $evaluations->get($run->id);
                    $metrics = json_decode($run->metrics, true) ?: [];

                    return ['id' => $run->id, 'persona' => $personas->firstWhere('id', $run->persona_id)?->name ?? 'Сценарий', 'status' => $run->status, 'created_at' => $run->created_at,
                        'release_status' => $releaseStatuses[$metrics['knowledge_release_id'] ?? ''] ?? null, 'turns' => json_decode($run->turns, true) ?: [],
                        'scores' => $evaluation ? (json_decode($evaluation->scores, true) ?: []) : [], 'issues' => $evaluation ? (json_decode($evaluation->issues, true) ?: []) : [], 'average' => $evaluation?->average, 'cost' => null];
                })->all()];
        });

        return Inertia::render('Seller/Scenarios/Runs', [...$data, 'status' => $request->session()->get('status')]);
    }

    public function runAll(Request $request): mixed
    {
        $this->access($request, function (TenantContext $tenant): void {
            if (config('runtime.ai_driver', 'fake') !== 'fake' || config('features.E3-06D.defaults.runtime_enabled', true) === false) {
                throw ValidationException::withMessages(['scenarios' => 'Защищённый fake runtime недоступен.']);
            }
            $release = DB::table('brands as b')->join('knowledge_releases as r', function ($join): void {
                $join->on('r.id', '=', 'b.active_knowledge_release_id')->on('r.organization_id', '=', 'b.organization_id')->on('r.brand_id', '=', 'b.id');
            })->where('b.organization_id', $tenant->organizationId)->where('b.id', $tenant->brandId)->where('r.status', 'published')->value('r.id');
            if (! $release) {
                throw ValidationException::withMessages(['scenarios' => 'Опубликованная версия знаний недоступна.']);
            }
            $personas = DB::table('seller_personas')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->get(['key', 'payload', 'max_turns'])->filter(fn ($persona) => (json_decode($persona->payload, true)['in_set'] ?? true) === true);
            if ($personas->isEmpty()) {
                throw ValidationException::withMessages(['scenarios' => 'В наборе нет сценариев для запуска.']);
            }
            foreach ($personas as $persona) {
                SimulateFakeJob::dispatch($tenant->organizationId, $tenant->brandId, $persona->key, array_fill(0, (int) $persona->max_turns, []));
            }
        });

        return back()->with('status', 'Все сценарии набора отправлены в очередь.');
    }

    public function store(Request $request): mixed
    {
        $input = $request->validate(['scenarios' => ['required', 'array', 'min:1', 'max:29'], 'scenarios.*' => ['required', 'ulid', 'distinct'], 'knowledge' => ['required', Rule::in(['published'])], 'model' => ['required', Rule::in(['fake'])]]);
        $this->access($request, function (TenantContext $tenant) use ($input): void {
            if (config('runtime.ai_driver', 'fake') !== 'fake' || config('features.E3-06D.defaults.runtime_enabled', true) === false) {
                throw ValidationException::withMessages(['model' => 'Защищённый fake runtime недоступен.']);
            }
            $release = DB::table('brands as b')->join('knowledge_releases as r', function ($join): void {
                $join->on('r.id', '=', 'b.active_knowledge_release_id')->on('r.organization_id', '=', 'b.organization_id')->on('r.brand_id', '=', 'b.id');
            })->where('b.organization_id', $tenant->organizationId)->where('b.id', $tenant->brandId)->where('r.status', 'published')->value('r.id');
            if (! $release) {
                throw ValidationException::withMessages(['knowledge' => 'Опубликованная версия знаний недоступна.']);
            }
            $personas = DB::table('seller_personas')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->whereIn('id', $input['scenarios'])->get(['id', 'key', 'name', 'max_turns']);
            if ($personas->count() !== count($input['scenarios'])) {
                throw ValidationException::withMessages(['scenarios' => 'Один из сценариев недоступен.']);
            }
            $groupId = (string) Str::ulid();
            $items = $personas->map(fn ($persona) => ['id' => $persona->id, 'key' => $persona->key, 'name' => $persona->name, 'max_turns' => (int) $persona->max_turns, 'status' => 'queued'])->all();
            DB::table('seller_scenario_run_groups')->insert(['id' => $groupId, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId, 'status' => 'queued', 'items' => json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
            RunScenarioGroup::dispatch($tenant->organizationId, $tenant->brandId, $groupId)->afterCommit();
        });

        return back()->with('status', 'Прогоны отправлены в очередь.');
    }
}
