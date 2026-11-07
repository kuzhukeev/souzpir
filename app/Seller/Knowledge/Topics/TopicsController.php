<?php

namespace App\Seller\Knowledge\Topics;

use App\Models\User;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeNotReady;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class TopicsController
{
    private function access(Request $request, string $permission, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($permission, $callback) {
            Gate::forUser($user)->authorize($permission);
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($user, $tenant);
        });
    }

    private function draft(TenantContext $tenant): ?object
    {
        return DB::table('knowledge_releases')->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->where('status', 'draft')->first(['id', 'version']);
    }

    public function index(Request $request): mixed
    {
        $data = $this->access($request, 'seller.knowledge.read', function (User $user, TenantContext $tenant): array {
            $repository = app(KnowledgeRepository::class);
            $canWrite = Gate::forUser($user)->allows('seller.knowledge.write');
            $draft = $this->draft($tenant);
            try {
                $snapshot = $draft && $canWrite ? $repository->forRelease($tenant, $draft->id, true) : $repository->published($tenant);
                $source = $snapshot->toArray();
            } catch (KnowledgeNotReady) {
                $source = array_fill_keys(KnowledgeSnapshot::FILES, []);
            }
            $facts = array_map(fn (array $group): array => ['id' => $group['id'], 'label' => $group['topic'] ?? $group['id'], 'count' => count($group['facts'] ?? [])], $source['facts']);
            $topics = array_map(fn (array $intent): array => [
                ...$intent,
                'title' => $intent['title'] ?? str_replace('_', ' ', $intent['id']),
                'examples' => $intent['examples'] ?? [],
                'default_answer' => $intent['default_answer'] ?? '',
                'after_action' => $intent['after_action'] ?? 'none',
                'after_action_text' => $intent['after_action_text'] ?? '',
            ], $source['intents']);
            $frequencies = [];
            $questionCounts = [];
            $answers = [];
            $unknown = [];
            $rows = DB::table('messages')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->where('created_at', '>=', now()->subDays(30))->whereNotNull('meta')->get(['meta', 'role', 'text']);
            foreach ($rows as $row) {
                $meta = json_decode($row->meta, true) ?: [];
                $intent = $meta['intent'] ?? null;
                if (is_string($intent)) {
                    if ($row->role === 'agent') {
                        $answers[$intent] = ($answers[$intent] ?? 0) + 1;
                        if (($meta['goal_kind'] ?? null) === 'unknown_fact' || ($meta['guard'] ?? null) === 'fallback' || ($meta['guard_fallback'] ?? false) === true) {
                            $unknown[$intent] = ($unknown[$intent] ?? 0) + 1;
                        }
                    }
                    $question = trim($row->text);
                    if ($row->role === 'client') {
                        $frequencies[$intent] = ($frequencies[$intent] ?? 0) + 1;
                    }
                    if ($row->role === 'client' && $question !== '') {
                        $question = mb_substr($question, 0, 200);
                        $questionCounts[$intent][$question] = ($questionCounts[$intent][$question] ?? 0) + 1;
                    }
                }
            }
            $frequentQuestions = [];
            foreach ($questionCounts as $intent => $counts) {
                arsort($counts);
                $frequentQuestions[$intent] = array_slice(array_keys($counts), 0, 5);
            }

            $unknownRates = [];
            foreach ($answers as $intent => $count) {
                $unknownRates[$intent] = round(100 * ($unknown[$intent] ?? 0) / $count, 1);
            }

            return ['unknownRates' => $unknownRates, 'topics' => $topics, 'facts' => $facts, 'frequencies' => $frequencies, 'frequentQuestions' => $frequentQuestions, 'canWrite' => $canWrite,
                'draftVersion' => $canWrite ? $draft?->version : null, 'status' => session('status')];
        });

        return Inertia::render('Seller/Knowledge/Topics/Index', $data);
    }

    public function createDraft(Request $request): mixed
    {
        $this->access($request, 'seller.knowledge.write', function (User $user, TenantContext $tenant): void {
            abort_if($this->draft($tenant), 409);
            try {
                $data = app(KnowledgeRepository::class)->published($tenant)->toArray();
            } catch (KnowledgeNotReady) {
                $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
            }
            app(KnowledgeRepository::class)->createDraft($tenant, $data);
        });

        return redirect()->route('seller.knowledge.topics.index')->with('status', 'Черновик создан.');
    }

    public function save(Request $request, ?string $intent = null): mixed
    {
        $input = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:500'],
            'examples' => ['array', 'max:12'], 'examples.*' => ['required', 'string', 'max:200'],
            'facts' => ['array', 'max:100'], 'facts.*' => ['required', 'string', 'max:120', 'distinct'],
            'default_answer' => ['nullable', 'string', 'max:500'],
            'after_action' => ['required', Rule::in(['none', 'bridge'])],
            'after_action_text' => ['nullable', 'string', 'max:200', 'required_if:after_action,bridge'],
        ]);
        $this->access($request, 'seller.knowledge.write', function (User $user, TenantContext $tenant) use ($input, $intent): void {
            $draft = $this->draft($tenant) ?? abort(409, 'Сначала создайте черновик.');
            $repository = app(KnowledgeRepository::class);
            $data = $repository->forRelease($tenant, $draft->id, true)->toArray();
            $facts = $input['facts'] ?? [];
            $knownFacts = array_column($data['facts'], 'id');
            abort_unless(count(array_diff($facts, $knownFacts)) === 0, 422, 'Выберите факты из списка.');
            $index = null;
            foreach ($data['intents'] as $position => $item) {
                if ($item['id'] === $intent) {
                    $index = $position;
                    break;
                }
            }
            if ($intent !== null) {
                abort_if($index === null, 404);
                $entry = $data['intents'][$index];
            } else {
                $entry = ['id' => 'topic_'.strtolower((string) Str::ulid())];
            }
            $entry = [...$entry, 'title' => trim($input['title']), 'description' => trim($input['description']),
                'examples' => array_values($input['examples'] ?? []), 'facts' => array_values($facts),
                'default_answer' => trim($input['default_answer'] ?? ''), 'after_action' => $input['after_action'],
                'after_action_text' => $input['after_action'] === 'bridge' ? trim($input['after_action_text'] ?? '') : ''];
            if ($index === null) {
                $data['intents'][] = $entry;
            } else {
                $data['intents'][$index] = $entry;
            }
            $repository->saveDraft($tenant, $draft->id, (int) $input['version'], $data);
        });

        return redirect()->route('seller.knowledge.topics.index')->with('status', 'Тема сохранена в черновике.');
    }
}
