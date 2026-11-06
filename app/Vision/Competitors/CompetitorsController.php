<?php

namespace App\Vision\Competitors;

use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Organizations\ModuleEntitlements;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use App\Vision\Core\VisionReportReader;
use App\Vision\KnowledgeBridge\SelfDeclaredProfile;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class CompetitorsController
{
    private function access(Request $request, string $permission, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($permission, $callback) {
            Gate::forUser($user)->authorize($permission);
            abort_unless(app(ModuleEntitlements::class)->enabled($tenant, 'vision'), 404);

            return $callback($user, $tenant);
        });
    }

    private function scope(string $table, TenantContext $tenant): Builder
    {
        return DB::table($table)->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId);
    }

    public function index(Request $request): mixed
    {
        $data = $this->access($request, 'vision.report.read', function (User $user, TenantContext $tenant) use ($request): array {
            $declared = [];
            if (Gate::forUser($user)->allows('vision.quiz.write')) {
                $profileId = $this->scope('brand_profiles', $tenant)->orderByDesc('version')->value('id');
                if ($profileId) {
                    try {
                        $declared = app(SelfDeclaredProfile::class)->read($request, $profileId)['competitors'];
                    } catch (\LogicException|\InvalidArgumentException) {
                        // An inaccessible or invalid profile must not be displayed.
                    }
                }
            }
            $distinctPairs = $this->scope('vision_competitor_distinct', $tenant)->get(['first_name', 'second_name'])->map(fn ($row) => [$row->first_name, $row->second_name])->all();
            $confirmed = $this->scope('snapshot_confirmations', $tenant)->pluck('measurement_id');
            $history = $this->scope('vision_reports', $tenant)->whereIn('measurement_id', $confirmed)->orderByDesc('created_at')->limit(20)->get(['id', 'measurement_id', 'created_at'])->toArray();
            $snapshot = app(SnapshotView::class)->current($tenant);
            if (! $snapshot) {
                return ['distinctPairs' => $distinctPairs, 'declared' => $declared, 'history' => $history, 'entities' => [], 'available' => false, 'canEdit' => Gate::forUser($user)->allows('vision.actions.write')];
            }
            $aliases = $this->scope('vision_competitor_aliases', $tenant)->pluck('canonical', 'alias')->all();
            $tracked = $this->scope('vision_competitor_tracking', $tenant)->pluck('name')->all();
            $deltas = null;
            $historySeries = [];
            foreach ($history as $previous) {
                if (! isset($snapshot['measurement_id']) || $previous->measurement_id === $snapshot['measurement_id']) {
                    continue;
                }
                try {
                    $earlier = app(VisionReportReader::class)->read($previous->id);
                    $difference = app(ComparableCompanyHistory::class)->deltas($earlier, $snapshot, $aliases);
                    if ($difference !== null) {
                        $deltas ??= $difference;
                        $historySeries[] = ['date' => $previous->created_at, 'difference' => $difference];
                    }
                } catch (\LogicException) {
                    // Skip unavailable or unverified reports.
                }
            }
            $metrics = app(SnapshotView::class)->metrics($snapshot, $aliases);
            $entities = collect($metrics['entities'] ?? [])->map(function ($entity) use ($metrics, $tracked, $snapshot, $aliases, $deltas, $historySeries, $history): array {
                $matched = collect($metrics['slots'] ?? [])->filter(function ($slot) use ($entity): bool {
                    if (! ($slot['valid'] ?? false)) {
                        return false;
                    }
                    foreach ($slot['mentions'] ?? [] as $mention) {
                        if (($mention['name'] ?? null) === $entity['name']) {
                            return true;
                        }
                    }

                    return false;
                });
                $questions = collect($snapshot['questions'] ?? [])->keyBy('id');

                $intentRates = [];
                $intentDenominators = [];
                $intentBases = [];
                foreach ($metrics['intents'] ?? [] as $intent => $group) {
                    $match = collect($group['entities'] ?? [])->firstWhere('name', $entity['name']);
                    $intentRates[$intent] = is_numeric($match['frequency'] ?? null) ? (float) $match['frequency'] : null;
                    $intentDenominators[$intent] = is_numeric($match['denominator'] ?? null) ? (int) $match['denominator'] : null;
                    $intentBases[$intent] = $this->completeAnswerBase($metrics['slots'] ?? [], (string) $intent, $intentDenominators[$intent]);
                }

                $quotes = ($entity['is_brand'] ?? false) ? $matched->filter(fn ($slot) => ($slot['fact']['brand_mentioned'] ?? false) === true
                    && ($slot['fact']['mention_status'] ?? null) === 'confirmed' && is_string($slot['fact']['mention_quote'] ?? null)
                    && trim($slot['fact']['mention_quote']) !== '')
                    ->map(fn ($slot) => ['text' => $slot['fact']['mention_quote'], 'provider' => $slot['provider'] ?? ''])
                    ->values()->all() : [];
                $providerModels = is_array($snapshot['providers'] ?? null) ? $snapshot['providers'] : [];
                $withoutBrand = $matched->filter(fn ($slot) => ($slot['mentions_complete'] ?? false) === true
                    && ($slot['fact']['brand_mentioned'] ?? null) === false)
                    ->groupBy('qid')->map(fn ($slots, $id) => [
                        'text' => data_get($questions->get($id), 'text', ''),
                        'intent' => $slots->pluck('intent')->filter()->first(),
                        'models' => $slots->pluck('provider')->filter()->unique()->values()->all(),
                        'model_names' => $slots->pluck('provider')->map(fn ($provider) => is_string($provider) && is_string($providerModels[$provider] ?? null) && trim($providerModels[$provider]) !== '' ? $providerModels[$provider] : null)->filter()->unique()->values()->all(),
                        'model_names_unknown' => $slots->pluck('provider')->contains(fn ($provider) => ! is_string($provider) || ! is_string($providerModels[$provider] ?? null) || trim($providerModels[$provider]) === ''),
                    ])->filter(fn ($item) => is_string($item['text']) && $item['text'] !== '')->values()->all();
                $series = collect(array_reverse($historySeries))->map(fn ($point) => [
                    'date' => $point['date'], 'answers' => max(0, $entity['answers'] - ($point['difference'][$entity['name']] ?? $entity['answers'])),
                ])->all();
                if ($series && isset($history[0])) {
                    $series[] = ['date' => $history[0]->created_at, 'answers' => $entity['answers']];
                }

                $modelNames = $matched->pluck('provider')->unique()->map(fn ($provider) => is_string($provider) && is_string($providerModels[$provider] ?? null) && trim($providerModels[$provider]) !== '' ? $providerModels[$provider] : null);
                $knownModels = $modelNames->filter()->unique()->values()->all();
                $unknownModels = $modelNames->contains(null);
                $modelPositions = collect($entity['positions_by_provider'] ?? [])->map(function ($position, $provider) use ($providerModels): array {
                    $model = $providerModels[$provider] ?? null;

                    return ['provider' => $provider, 'model' => is_string($model) && trim($model) !== '' ? $model : null, 'position' => $position];
                })->values()->all();

                return ['name' => $entity['name'], 'answers' => $entity['answers'], 'share' => $entity['share_of_voice'] ?? null,
                    'position' => $entity['position'] ?? null, 'model_names' => $knownModels, 'model_names_unknown' => $unknownModels, 'model_positions' => $modelPositions,
                    'quotes' => $quotes, 'delta' => $deltas === null ? null : ($deltas[$entity['name']] ?? null), 'history_series' => $series,
                    'question_count' => $matched->filter(fn ($slot) => ($slot['mentions_complete'] ?? false) === true)->pluck('qid')->filter()->unique()->count(),
                    'question_total' => count($snapshot['questions'] ?? []), 'questions_without_brand' => $withoutBrand,
                    'intent_rates' => $intentRates, 'intent_denominators' => $intentDenominators, 'intent_bases' => $intentBases,
                    'aliases' => array_values(array_keys($aliases, $entity['name'], true)),
                    'is_brand' => $entity['is_brand'], 'tracked' => in_array($entity['name'], $tracked, true),
                    'models' => $matched->pluck('provider')->unique()->values()->all(),
                    'intents' => $matched->pluck('intent')->filter()->unique()->values()->all(),
                    'questions' => $matched->pluck('qid')->unique()->map(fn ($id) => data_get($questions->get($id), 'text'))->filter()->values()->all(),
                    'source_ranking' => app(SourceRanking::class)->forSlots($matched),
                    'sources' => $matched->flatMap(fn ($slot) => array_column($slot['sources'] ?? [], 'domain'))->filter()->unique()->values()->all()];
            })->values()->all();

            return ['distinctPairs' => $distinctPairs, 'declared' => $declared, 'history' => $history, 'entities' => $entities, 'available' => true, 'canEdit' => Gate::forUser($user)->allows('vision.actions.write')];
        });

        return Inertia::render('Vision/Competitors/Index', [...$data, 'status' => $request->session()->get('status')]);
    }

    /** A radar is comparable only if each denominator covers the same identified, complete answers. */
    private function completeAnswerBase(array $slots, string $intent, ?int $denominator): ?string
    {
        if (! $denominator) {
            return null;
        }
        $keys = [];
        foreach ($slots as $slot) {
            if (! ($slot['valid'] ?? false) || ($slot['intent'] ?? 'choose') !== $intent) {
                continue;
            }
            if (($slot['mentions_complete'] ?? false) !== true || ! isset($slot['provider'], $slot['question_n'], $slot['run_no'])) {
                return null;
            }
            $keys[] = json_encode([$slot['provider'], $slot['question_n'], $slot['run_no']], JSON_THROW_ON_ERROR);
        }
        if (count($keys) !== $denominator || count(array_unique($keys)) !== $denominator) {
            return null;
        }
        sort($keys, SORT_STRING);

        return hash('sha256', implode('|', $keys));
    }

    private function validNames(TenantContext $tenant): array
    {
        $snapshot = app(SnapshotView::class)->current($tenant) ?? abort(404);
        $aliases = $this->scope('vision_competitor_aliases', $tenant)->pluck('canonical', 'alias')->all();

        return array_column(app(SnapshotView::class)->metrics($snapshot, $aliases)['entities'] ?? [], 'name');
    }

    public function merge(Request $request): mixed
    {
        $input = $request->validate(['source' => ['required', 'string', 'max:255', 'different:target'], 'target' => ['required', 'string', 'max:255'], 'distinct' => ['sometimes', 'boolean']]);
        $this->access($request, 'vision.actions.write', function (User $user, TenantContext $tenant) use ($input): void {
            DB::transaction(function () use ($tenant, $input): void {
                $names = $this->validNames($tenant);
                if (! in_array($input['source'], $names, true) || ! in_array($input['target'], $names, true)) {
                    throw ValidationException::withMessages(['source' => 'Выберите названные компании текущего снимка.']);
                }
                if ($input['distinct'] ?? false) {
                    $pair = [$input['source'], $input['target']];
                    sort($pair, SORT_STRING);
                    $row = $this->scope('vision_competitor_distinct', $tenant)->where('first_name', $pair[0])->where('second_name', $pair[1])->first();
                    if (! $row) {
                        $id = (string) Str::ulid();
                        DB::table('vision_competitor_distinct')->insert(['id' => $id, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId, 'first_name' => $pair[0], 'second_name' => $pair[1], 'created_at' => now(), 'updated_at' => now()]);
                        app(AuditWriter::class)->record($tenant, new AuditEntry('vision.competitor.distinct', 'vision_competitor_distinct', $id));
                    }

                    return;
                }
                $this->scope('vision_competitor_aliases', $tenant)->where('canonical', $input['source'])->update(['canonical' => $input['target'], 'updated_at' => now()]);
                $existing = $this->scope('vision_competitor_aliases', $tenant)->where('alias', $input['source'])->first();
                DB::table('vision_competitor_aliases')->updateOrInsert(['brand_id' => $tenant->brandId, 'alias' => $input['source']],
                    ['id' => $existing?->id ?? (string) Str::ulid(), 'organization_id' => $tenant->organizationId, 'canonical' => $input['target'], 'created_at' => $existing?->created_at ?? now(), 'updated_at' => now()]);
                $oldTracking = $this->scope('vision_competitor_tracking', $tenant)->where('name', $input['source'])->first();
                if ($oldTracking) {
                    $targetTracking = $this->scope('vision_competitor_tracking', $tenant)->where('name', $input['target'])->first();
                    if ($targetTracking) {
                        $this->scope('vision_competitor_tracking', $tenant)->where('id', $oldTracking->id)->delete();
                    } else {
                        $this->scope('vision_competitor_tracking', $tenant)->where('id', $oldTracking->id)->update(['name' => $input['target'], 'updated_at' => now()]);
                    }
                }
                app(AuditWriter::class)->record($tenant, new AuditEntry('vision.competitor.merged', 'vision_competitor_alias', $existing?->id ?? DB::table('vision_competitor_aliases')->where('brand_id', $tenant->brandId)->where('alias', $input['source'])->value('id')));
            });
        });

        return back()->with('status', ($input['distinct'] ?? false) ? 'Отмечено: это разные компании.' : 'Названия объединены в этом разделе.');
    }

    public function track(Request $request): mixed
    {
        $input = $request->validate(['name' => ['required', 'string', 'max:255'], 'tracked' => ['required', 'boolean']]);
        $this->access($request, 'vision.actions.write', function (User $user, TenantContext $tenant) use ($input): void {
            if (! in_array($input['name'], $this->validNames($tenant), true)) {
                throw ValidationException::withMessages(['name' => 'Компания не найдена в снимке.']);
            }
            $query = $this->scope('vision_competitor_tracking', $tenant)->where('name', $input['name']);
            $row = $query->first();
            if ($input['tracked'] && ! $row) {
                DB::table('vision_competitor_tracking')->insert(['id' => (string) Str::ulid(), 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId, 'name' => $input['name'], 'created_at' => now(), 'updated_at' => now()]);
            }
            if (! $input['tracked'] && $row) {
                $query->delete();
            }
            app(AuditWriter::class)->record($tenant, new AuditEntry('vision.competitor.tracking.updated', 'brand', $tenant->brandId, after: ['tracked' => $input['tracked']]));
        });

        return back();
    }
}
