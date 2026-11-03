<?php

namespace App\Seller\Knowledge\Releases;

use App\Models\User;
use App\Seller\Evaluation\FakeSimulation;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeNotReady;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class ReleasesController
{
    private function access(Request $request, string $permission, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($permission, $callback) {
            Gate::forUser($user)->authorize($permission);
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($user, $tenant);
        });
    }

    private function query(string $table, TenantContext $tenant): Builder
    {
        return DB::table($table)->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId);
    }

    public function index(Request $request): mixed
    {
        $data = $this->access($request, 'seller.knowledge.read', function (User $user, TenantContext $tenant): array {
            $releases = $this->query('knowledge_releases', $tenant)->whereIn('status', ['draft', 'published'])->orderByDesc('created_at')->orderByDesc('id')->get(['id', 'status', 'version', 'published_at', 'updated_at', 'created_by']);
            $authors = DB::table('users')->whereIn('id', $releases->pluck('created_by')->filter()->unique())->pluck('name', 'id');
            $releases->each(fn ($release) => $release->author = $release->created_by ? $authors->get($release->created_by) : null);
            $brand = DB::table('brands')->where('organization_id', $tenant->organizationId)->where('id', $tenant->brandId)->first(['active_knowledge_release_id']);
            $draft = $releases->firstWhere('status', 'draft');
            $canWrite = Gate::forUser($user)->allows('seller.knowledge.write');
            $topics = $draft && $canWrite ? collect(app(KnowledgeRepository::class)->forRelease($tenant, $draft->id, true)->toArray()['intents'])
                ->map(fn (array $topic) => ['id' => $topic['id'] ?? '', 'label' => $topic['description'] ?? $topic['id'] ?? ''])
                ->filter(fn (array $topic) => $topic['id'] !== '')->values()->all() : [];
            $counts = [];
            try {
                $snapshot = app(KnowledgeRepository::class)->published($tenant)->toArray();
                $counts = [
                    'facts' => array_sum(array_map(fn ($group) => count($group['facts']), $snapshot['facts'])),
                    'conditions' => count($snapshot['conditions']),
                    'intents' => count($snapshot['intents']),
                    'examples' => count($snapshot['fewshot']['examples'] ?? (array_is_list($snapshot['fewshot']) ? $snapshot['fewshot'] : [])),
                    'closed_topics' => count($snapshot['closed_topics']),
                ];
            } catch (KnowledgeNotReady) {
            }
            $changeCount = null;
            $changes = [];
            $comparison = [];
            if ($draft && $brand?->active_knowledge_release_id && $canWrite) {
                $repository = app(KnowledgeRepository::class);
                $comparison = app(ReleaseComparison::class)->compare($repository->published($tenant), $repository->forRelease($tenant, $draft->id, true));
                $changeCount = array_sum(array_map(fn ($section) => count($section['changes']), $comparison));
                foreach ($comparison as $section => $result) {
                    foreach ($result['changes'] as $change) {
                        $changes[] = ['section' => $section, 'type' => $change['type'], 'name' => $change['key']];
                    }
                }
            }
            $since = now()->subDays(7);
            $dialogIds = $this->query('messages', $tenant)->where('role', 'client')->where('created_at', '>=', $since)->distinct()->pluck('conversation_id');
            $unanswered = $dialogIds->isEmpty() ? 0 : $this->query('seller_tasks', $tenant)->where('type', 'unknown_question')
                ->where('created_at', '>=', $since)->whereIn('conversation_id', $dialogIds)->distinct()->count('conversation_id');
            $coverage = $dialogIds->isEmpty() ? null : (int) round(100 * (1 - $unanswered / $dialogIds->count()));
            $notes = $this->query('audit_logs', $tenant)->where('action', 'knowledge.release.note')
                ->whereIn('subject_id', $releases->pluck('id'))->get(['subject_id', 'after'])->mapWithKeys(function ($row): array {
                    $value = json_decode($row->after, true);

                    return [$row->subject_id => is_array($value) ? ($value['note'] ?? null) : null];
                })->all();
            $tasks = $this->query('seller_tasks', $tenant)->where('type', 'unknown_question')->where('status', 'new')->orderByDesc('created_at')->limit(50)->get(['id', 'conversation_id', 'payload', 'created_at']);
            $reviews = $this->query('message_reviews', $tenant)->where('type', 'wrong_fact')->where('status', 'new')->orderByDesc('created_at')->limit(50)->get(['id', 'message_id', 'note', 'better_text', 'created_at']);
            $reviewDialogs = $this->query('messages', $tenant)->whereIn('id', $reviews->pluck('message_id'))->pluck('conversation_id', 'id');
            $reviews->each(fn ($review) => $review->conversation_id = $reviewDialogs->get($review->message_id));
            $handled = $this->query('audit_logs', $tenant)->where('action', 'knowledge.incoming.handled')->where('subject_type', 'evaluation')->pluck('subject_id');
            $evaluations = $this->query('seller_evaluations', $tenant)->whereNotIn('id', $handled)->whereRaw('jsonb_array_length(issues) > 0')->orderByDesc('created_at')->limit(50)->get(['id', 'issues', 'comment', 'created_at']);

            $lastRun = $draft ? $this->scenarioResult($tenant, $draft->id) : null;

            return ['scenarioRun' => $lastRun, 'releases' => $releases, 'activeId' => $brand?->active_knowledge_release_id, 'draft' => $draft, 'counts' => $counts,
                'tasks' => $tasks, 'reviews' => $reviews, 'evaluations' => $evaluations, 'changeCount' => $changeCount, 'changes' => $changes, 'comparison' => $comparison, 'coverage' => $coverage, 'publicationNotes' => $notes, 'topics' => $topics, 'canWrite' => $canWrite];
        });

        return Inertia::render('Seller/Knowledge/Releases/Index', [...$data, 'status' => session('status')]);
    }

    private function scenarioResult(TenantContext $tenant, string $release): ?array
    {
        $json = $this->query('audit_logs', $tenant)->where('action', 'knowledge.scenarios.checked')->where('subject_id', $release)->orderByDesc('created_at')->orderByDesc('id')->value('after');

        return $json ? json_decode($json, true) : null;
    }

    public function runScenarios(Request $request, string $release): mixed
    {
        $this->access($request, 'seller.knowledge.write', function (User $user, TenantContext $tenant) use ($release): array {
            $draft = $this->query('knowledge_releases', $tenant)->where('id', $release)->where('status', 'draft')->first() ?? abort(404);
            $scenarios = $this->query('seller_personas', $tenant)->orderBy('name')->get()->filter(function ($row): bool {
                $payload = json_decode($row->payload, true) ?: [];

                return ($payload['in_set'] ?? true) === true;
            });
            $runs = [];
            foreach ($scenarios as $scenario) {
                $payload = json_decode($scenario->payload, true) ?: [];
                $client = trim((string) ($payload['first_message'] ?? ''));
                $answer = 'Здравствуйте! Чем могу помочь?';
                $expectations = is_array($payload['expect'] ?? null) ? $payload['expect'] : [];
                $checks = [];
                foreach ($expectations as $expectation) {
                    $checks[$expectation] = match ($expectation) {
                        'one_question_per_turn' => mb_substr_count($answer, '?') <= 1,
                        'no_deferred_promise' => ! preg_match('/(позже|перезвон|напишу вам)/ui', $answer),
                        default => false,
                    };
                }
                $passed = $client !== '' && ! in_array(false, $checks, true);
                $id = app(FakeSimulation::class)->run($tenant, $scenario->key, [['client' => $client ?: 'Тестовый вопрос', 'agent' => $answer, 'meta' => ['expectation_checks' => $checks]]]);
                $runs[] = ['id' => $id, 'passed' => $passed];
            }
            $result = ['version' => $draft->version, 'runs' => $runs, 'passed' => count(array_filter($runs, fn ($run) => $run['passed'])), 'failed' => count(array_filter($runs, fn ($run) => ! $run['passed']))];
            app(AuditWriter::class)->record($tenant, new AuditEntry('knowledge.scenarios.checked', 'knowledge_release', $release, after: $result));

            return $result;
        });

        return back();
    }

    public function scenarioRun(Request $request, string $run): mixed
    {
        $data = $this->access($request, 'seller.knowledge.write', function (User $user, TenantContext $tenant) use ($run): array {
            $row = $this->query('seller_sim_runs', $tenant)->where('id', $run)->first() ?? abort(404);

            return ['run' => ['id' => $row->id, 'turns' => json_decode($row->turns, true, 512, JSON_THROW_ON_ERROR), 'created_at' => $row->created_at]];
        });

        return Inertia::render('Seller/Knowledge/Releases/Run', $data);
    }

    public function draft(Request $request): mixed
    {
        $this->access($request, 'seller.knowledge.write', function (User $user, TenantContext $tenant): void {
            $repository = app(KnowledgeRepository::class);
            try {
                $data = $repository->published($tenant)->toArray();
            } catch (KnowledgeNotReady) {
                $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
            }
            $repository->createDraft($tenant, $data);
        });

        return back()->with('status', 'Черновик создан.');
    }

    public function compare(Request $request): mixed
    {
        $input = $request->validate(['from' => ['required', 'ulid'], 'to' => ['required', 'ulid', 'different:from']]);
        $data = $this->access($request, 'seller.knowledge.read', function (User $user, TenantContext $tenant) use ($input): array {
            $repository = app(KnowledgeRepository::class);
            $before = $repository->forRelease($tenant, $input['from'], true);
            $after = $repository->forRelease($tenant, $input['to'], true);
            $releases = $this->query('knowledge_releases', $tenant)->whereIn('id', [$input['from'], $input['to']])->get(['id', 'status', 'version', 'published_at']);

            return ['from' => $releases->firstWhere('id', $input['from']), 'to' => $releases->firstWhere('id', $input['to']),
                'sections' => app(ReleaseComparison::class)->compare($before, $after)];
        });

        return Inertia::render('Seller/Knowledge/Releases/Compare', $data);
    }

    public function publish(Request $request, string $release): mixed
    {
        $input = $request->validate(['version' => ['required', 'integer', 'min:1'], 'note' => ['nullable', 'string', 'max:500'], 'confirm_failed' => ['sometimes', 'boolean']]);
        $this->access($request, 'seller.knowledge.write', function (User $user, TenantContext $tenant) use ($release, $input): void {
            $run = $this->scenarioResult($tenant, $release);
            if ($run && $run['version'] === (int) $input['version'] && $run['failed'] > 0 && ! ($input['confirm_failed'] ?? false)) {
                throw ValidationException::withMessages(['confirm_failed' => 'Подтвердите публикацию при проваленных сценариях.']);
            }
            DB::transaction(function () use ($tenant, $release, $input): void {
                $published = app(KnowledgeRepository::class)->publish($tenant, $release, (int) $input['version']);
                $note = trim($input['note'] ?? '');
                if ($note !== '') {
                    app(AuditWriter::class)->record($tenant, new AuditEntry('knowledge.release.note', 'knowledge_release', $published->releaseId(), after: ['note' => $note]));
                }
            });
        });

        return back()->with('status', 'Знания опубликованы.');
    }

    public function rollback(Request $request, string $release): mixed
    {
        $this->access($request, 'seller.knowledge.write', function (User $user, TenantContext $tenant) use ($release): void {
            $repository = app(KnowledgeRepository::class);
            $snapshot = $repository->forRelease($tenant, $release);
            if ($this->query('knowledge_releases', $tenant)->where('status', 'draft')->exists()) {
                throw ValidationException::withMessages(['rollback' => 'Уже есть черновик. Завершите работу с ним, прежде чем создать черновик из прошлой версии.']);
            }
            $repository->createDraft($tenant, $snapshot->toArray());
        });

        return back()->with('status', 'Создан черновик из выбранной версии. Активная публикация не изменилась.');
    }
}
