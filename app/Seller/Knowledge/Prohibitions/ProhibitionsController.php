<?php

namespace App\Seller\Knowledge\Prohibitions;

use App\Models\User;
use App\Seller\Engine\DeterministicGuard;
use App\Seller\Engine\Goal;
use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class ProhibitionsController
{
    private function access(Request $request, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($callback) {
            Gate::forUser($user)->authorize('seller.knowledge.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($tenant);
        });
    }

    private function draft(TenantContext $tenant): ?object
    {
        return DB::table('knowledge_releases')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('status', 'draft')->first(['id', 'version']);
    }

    public function index(Request $request): mixed
    {
        $data = $this->access($request, function (TenantContext $tenant): array {
            $draft = $this->draft($tenant);
            $data = $draft ? app(KnowledgeRepository::class)->forRelease($tenant, $draft->id, true)->toArray() : null;

            $counts = DB::table('prohibition_guard_hits')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->where('created_at', '>=', now()->subDays(30))->selectRaw('rule_id, count(*) as total')->groupBy('rule_id')->pluck('total', 'rule_id');
            $rules = array_map(fn (array $rule): array => [...$rule, 'hits30Days' => (int) ($counts[$rule['id']] ?? 0)], $data['guard_rules']['forbidden_promises'] ?? []);

            return ['draft' => $draft, 'topics' => $data['closed_topics'] ?? [], 'rules' => $rules,
                'maxWords' => $data['persona']['length']['max_words'] ?? 90, 'maxQuestions' => $data['persona']['length']['max_questions'] ?? 1,
                'stopPhrases' => $data['persona']['stop_phrases'] ?? [], 'stopPhrasesEnabled' => $data['persona']['stop_phrases_enabled'] ?? true];
        });

        return Inertia::render('Seller/Knowledge/Prohibitions/Index', [...$data, 'status' => session('status')]);
    }

    public function save(Request $request): mixed
    {
        $input = $request->validate(['version' => ['required', 'integer', 'min:1'], 'topics' => ['present', 'array', 'max:100'], 'topics.*.id' => ['nullable', 'string', 'max:100'],
            'topics.*.category' => ['required', 'string', 'max:100'], 'topics.*.idea' => ['required', 'string', 'max:300'], 'topics.*.next' => ['required', 'in:return,handoff'],
            'rules' => ['present', 'array', 'max:100'], 'rules.*.id' => ['required', 'string', 'max:100'], 'rules.*.severity' => ['required', 'in:block,warn'],
            'rules.*.patterns' => ['sometimes', 'array', 'max:50'], 'rules.*.patterns.*' => ['required', 'string', 'max:500'],
            'rules.*.enabled' => ['sometimes', 'boolean'], 'maxQuestions' => ['sometimes', 'integer', 'min:0', 'max:10'],
            'stopPhrases' => ['sometimes', 'array', 'max:100'], 'stopPhrases.*' => ['required', 'string', 'max:200'],
            'stopPhrasesEnabled' => ['sometimes', 'boolean'],
            'maxWords' => ['required', 'integer', 'min:20', 'max:300']]);
        $this->access($request, function (TenantContext $tenant) use ($input): void {
            $draft = $this->draft($tenant) ?? abort(404);
            $repo = app(KnowledgeRepository::class);
            $data = $repo->forRelease($tenant, $draft->id, true)->toArray();
            $topics = collect($data['closed_topics'])->keyBy('id');
            $rules = collect($data['guard_rules']['forbidden_promises'] ?? [])->keyBy('id');
            $existingTopicIds = array_values(array_filter(array_column($input['topics'], 'id'), fn ($id) => $id !== null));
            if (count($input['rules']) !== $rules->count()
                || count(array_unique($existingTopicIds)) !== count($existingTopicIds) || count(array_unique(array_column($input['rules'], 'id'))) !== count($input['rules'])) {
                throw ValidationException::withMessages(['topics' => 'Список правил изменился; обновите страницу.']);
            }
            $updatedTopics = [];
            foreach ($input['topics'] as $item) {
                $id = $item['id'] ?? null;
                if ($id !== null && ! $topics->has($id)) {
                    throw ValidationException::withMessages(['topics' => 'Неизвестная тема.']);
                }
                $original = $id !== null ? $topics[$id] : ['id' => (string) Str::ulid(), 'client_examples' => []];
                $original['category'] = $item['category'];
                $original['idea'] = $item['idea'];
                $original['next'] = $item['next'];
                $updatedTopics[] = $original;
            }
            foreach ($input['rules'] as $item) {
                if (! $rules->has($item['id'])) {
                    throw ValidationException::withMessages(['rules' => 'Неизвестное правило.']);
                }
                $original = $rules[$item['id']];
                $original['severity'] = $item['severity'];
                if (array_key_exists('enabled', $item)) {
                    $original['enabled'] = (bool) $item['enabled'];
                }
                if (array_key_exists('patterns', $item)) {
                    foreach ($item['patterns'] as $pattern) {
                        if (@preg_match('~'.str_replace('~', '\\~', $pattern).'~ium', '') === false) {
                            throw ValidationException::withMessages(['rules' => 'Проверьте синтаксис паттерна.']);
                        }
                    }
                    $original['patterns'] = array_values($item['patterns']);
                }
                $rules[$item['id']] = $original;
            }
            $data['closed_topics'] = $updatedTopics;
            $data['guard_rules']['forbidden_promises'] = $rules->values()->all();
            $data['persona']['length'] ??= [];
            $data['persona']['length']['max_words'] = (int) $input['maxWords'];
            if (array_key_exists('maxQuestions', $input)) {
                $data['persona']['length']['max_questions'] = (int) $input['maxQuestions'];
            }
            if (array_key_exists('stopPhrases', $input)) {
                $data['persona']['stop_phrases'] = array_values($input['stopPhrases']);
            }
            if (array_key_exists('stopPhrasesEnabled', $input)) {
                $data['persona']['stop_phrases_enabled'] = (bool) $input['stopPhrasesEnabled'];
            }
            $repo->saveDraft($tenant, $draft->id, (int) $input['version'], $data);
        });

        return back()->with('status', 'Правила сохранены в черновике.');
    }

    public function check(Request $request): mixed
    {
        $input = $request->validate(['text' => ['required', 'string', 'max:5000']]);
        $result = $this->access($request, function (TenantContext $tenant) use ($input): array {
            $draft = $this->draft($tenant) ?? abort(404);
            $snapshot = app(KnowledgeRepository::class)->forRelease($tenant, $draft->id, true);

            $result = (new DeterministicGuard($snapshot))->check($input['text'], new Goal('answer_only'), new LeadState);
            $result['matches'] = [];
            foreach ($snapshot->guardRules()['forbidden_promises'] ?? [] as $rule) {
                if (($rule['enabled'] ?? true) === false) {
                    continue;
                }
                if (collect($rule['allow_if'] ?? [])->contains(fn ($value) => str_contains(mb_strtolower($input['text']), mb_strtolower($value)))) {
                    continue;
                }
                $issue = 'запрещённое обещание: '.($rule['description'] ?? '');
                if (! in_array($issue, [...$result['issues'], ...$result['warnings']], true)) {
                    continue;
                }
                foreach ($rule['patterns'] ?? [] as $pattern) {
                    if (@preg_match('~'.str_replace('~', '\\~', $pattern).'~ium', $input['text'], $match) === 1 && $match[0] !== '') {
                        $result['matches'][] = ['text' => $match[0], 'rule' => $rule['description'] ?? 'Правило'];
                        break;
                    }
                }
            }

            return $result;
        });

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }
}
