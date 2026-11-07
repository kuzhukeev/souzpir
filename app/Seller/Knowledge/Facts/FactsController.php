<?php

namespace App\Seller\Knowledge\Facts;

use App\Models\User;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class FactsController
{
    private function access(Request $request, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($callback) {
            Gate::forUser($user)->authorize('seller.knowledge.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($tenant);
        });
    }

    private function draft(TenantContext $tenant): string
    {
        return DB::table('knowledge_releases')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('status', 'draft')->value('id') ?? abort(404, 'Создайте черновик базы знаний.');
    }

    public function index(Request $request): mixed
    {
        $editor = $this->access($request, function (TenantContext $tenant): ?array {
            $id = DB::table('knowledge_releases')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('status', 'draft')->value('id');
            if (! $id) {
                return null;
            }
            $repository = app(KnowledgeRepository::class);
            $editor = $repository->draftEditor($tenant, $id);
            $data = $repository->forRelease($tenant, $id, true)->toArray();
            $metadata = collect($data['facts'])->keyBy('id');
            foreach ($editor['groups'] as &$group) {
                $group['metadata'] = $metadata[$group['key']] ?? [];
            }
            unset($group);
            $publishedId = DB::table('brands')->where('organization_id', $tenant->organizationId)->where('id', $tenant->brandId)->value('active_knowledge_release_id');
            $published = $publishedId ? DB::table('kn_facts as fact')->join('kn_fact_groups as groups', 'groups.id', '=', 'fact.group_id')
                ->where('groups.release_id', $publishedId)->get(['fact.logical_id', 'fact.text', 'fact.status', 'fact.source', 'groups.key'])->keyBy('logical_id') : collect();
            foreach ($editor['groups'] as &$group) {
                foreach ($group['facts'] as &$fact) {
                    $old = $published[$fact['logical_id']] ?? null;
                    $fact['change_status'] = ! $old ? 'new' : (($old->text !== $fact['text'] || $old->status !== $fact['status'] || $old->source !== $fact['source'] || $old->key !== $group['key']) ? 'changed' : null);
                }
                unset($fact);
            }
            unset($group);
            $logicalIds = collect($editor['groups'])->flatMap(fn (array $group) => array_column($group['facts'], 'logical_id'))->all();
            $changed = $logicalIds ? DB::table('audit_logs')->where('organization_id', $tenant->organizationId)
                ->where('brand_id', $tenant->brandId)->where('subject_type', 'knowledge_fact')
                ->whereIn('subject_id', $logicalIds)->selectRaw('subject_id, max(created_at) as changed_at')
                ->groupBy('subject_id')->pluck('changed_at', 'subject_id') : collect();
            foreach ($editor['groups'] as &$group) {
                foreach ($group['facts'] as &$fact) {
                    $fact['changed_at'] = $changed[$fact['logical_id']] ?? null;
                }
                unset($fact);
            }
            unset($group);

            return $editor;
        });

        return Inertia::render('Seller/Knowledge/Facts/Index', ['editor' => $editor, 'status' => session('status')]);
    }

    public function show(Request $request, string $fact): mixed
    {
        // A second access() call within the same request (partial reload for factData) would
        // re-check the brand-revision snapshot against a now-stale value and abort with 409,
        // so the tenant is resolved once here and reused by the deferred closure below.
        $tenant = $this->access($request, function (TenantContext $tenant) use ($fact): TenantContext {
            $draft = $this->draft($tenant);
            DB::table('kn_facts as f')->join('kn_fact_groups as g', 'g.id', '=', 'f.group_id')
                ->where('g.release_id', $draft)->where('f.id', $fact)->exists() || abort(404);

            return $tenant;
        });

        return Inertia::render('Seller/Knowledge/Facts/Show', [
            'factData' => Inertia::defer(function () use ($tenant, $fact): array {
                $draft = $this->draft($tenant);
                $row = DB::table('kn_facts as f')->join('kn_fact_groups as g', 'g.id', '=', 'f.group_id')
                    ->where('g.release_id', $draft)->where('f.id', $fact)
                    ->first(['f.id', 'f.logical_id', 'f.text', 'f.status', 'f.source', 'g.key as group_key', 'g.data as group_data']) ?? abort(404);
                $group = json_decode($row->group_data, true);
                unset($row->group_data);
                $version = DB::table('knowledge_releases')->where('id', $draft)->value('version');
                $publishedId = DB::table('brands')->where('organization_id', $tenant->organizationId)->where('id', $tenant->brandId)->value('active_knowledge_release_id');
                $publishedRow = $publishedId ? DB::table('kn_facts as f')->join('kn_fact_groups as g', 'g.id', '=', 'f.group_id')
                    ->join('knowledge_releases as r', 'r.id', '=', 'g.release_id')
                    ->where('r.organization_id', $tenant->organizationId)->where('r.brand_id', $tenant->brandId)
                    ->where('r.id', $publishedId)->where('f.logical_id', $row->logical_id)->first(['f.text', 'f.status', 'f.source', 'g.key']) : null;
                $publishedText = $publishedRow->text ?? null;
                $row->change_status = ! $publishedRow ? 'new' : (($publishedRow->text !== $row->text || $publishedRow->status !== $row->status || $publishedRow->source !== $row->source || $publishedRow->key !== $row->group_key) ? 'changed' : null);
                $history = DB::table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')
                    ->where('a.organization_id', $tenant->organizationId)->where('a.brand_id', $tenant->brandId)
                    ->where('a.subject_type', 'knowledge_fact')->where('a.subject_id', $row->logical_id)
                    ->orderByDesc('a.created_at')->orderByDesc('a.id')->limit(50)->get(['a.action', 'a.created_at', 'a.before', 'a.after', 'u.name as author'])
                    ->map(function ($change) {
                        $before = json_decode($change->before ?? '{}', true);
                        $after = json_decode($change->after ?? '{}', true);

                        return ['action' => $change->action, 'created_at' => $change->created_at, 'author' => $change->author,
                            'before_status' => $before['status'] ?? null, 'after_status' => $after['status'] ?? null, 'before_text' => $before['text'] ?? null, 'after_text' => $after['text'] ?? null];
                    });

                $snapshot = app(KnowledgeRepository::class)->forRelease($tenant, $draft, true);
                $conditions = $snapshot->conditions();
                $intentLinks = collect($snapshot->intents())->filter(fn ($intent) => is_array($intent) && is_string($intent['id'] ?? null) && is_array($intent['facts'] ?? null))
                    ->map(fn ($intent) => ['id' => $intent['id'], 'label' => is_string($intent['description'] ?? null) && trim($intent['description']) !== '' ? $intent['description'] : $intent['id'], 'groups' => array_slice($intent['facts'], 0, 3),
                        'selected' => in_array($row->group_key, array_slice($intent['facts'], 0, 3), true) && (! array_key_exists($row->group_key, $intent['fact_overrides'] ?? []) || in_array($row->logical_id, $intent['fact_overrides'][$row->group_key], true))])->values()->all();
                $conditionLabels = [
                    'min_order_rub' => 'Минимальный заказ',
                    'min_order_quantity' => 'Минимальное количество',
                    'prepayment_pct' => 'Предоплата, %',
                    'availability' => 'Условия наличия',
                    'other_conditions' => 'Дополнительные условия',
                    'offseason_min_order_rub' => 'Минимальный заказ вне сезона',
                    'reserve_days' => 'Срок резерва',
                    'new_client_bonus_pct' => 'Бонус новому клиенту',
                    'discount_grid' => 'Сетка скидок',
                    'box_discounts' => 'Скидки по маркам',
                    'promotions' => 'Акции',
                    'valid_from' => 'Дата начала действия',
                    'approved_by' => 'Кем утверждено',
                    'max_pct_unapproved' => 'Максимальная скидка без утверждения',
                    'escalation_amount_rub' => 'Сумма для передачи руководителю',
                    'discount_not_tied_to_volume' => 'Скидка не привязана к объёму',
                    'prepayment' => 'Предоплата',
                    'partial_prepayment_allowed' => 'Частичная предоплата',
                    'payment_methods' => 'Способы оплаты',
                    'deferred_payment.new_clients' => 'Отсрочка новым клиентам',
                    'deferred_payment.regular_clients' => 'Отсрочка постоянным клиентам',
                    'consignment.new_clients' => 'Реализация новым клиентам',
                    'consignment.regular_clients' => 'Реализация постоянным клиентам',
                    'returns' => 'Возврат',
                    'delivery.free_to_moscow_terminal' => 'Доставка до терминала Москвы',
                    'delivery.main_carrier' => 'Основной перевозчик',
                    'delivery.carriers' => 'Перевозчики',
                    'delivery.sdek' => 'Доставка СДЭК',
                    'delivery.box_weight_kg' => 'Вес коробки',
                    'delivery.assembly_days' => 'Срок сборки',
                    'price_increase_forecast.month' => 'Месяц повышения цен',
                    'price_increase_forecast.pct_min' => 'Минимальное повышение цен',
                    'price_increase_forecast.pct_max' => 'Максимальное повышение цен',
                    'price_increase_forecast.leader_brand' => 'Марка с повышением цен',
                    'price_increase_forecast.example' => 'Пример повышения цен',
                    'followup_default_days' => 'Срок повторного контакта',
                    'followup_max_auto' => 'Число автоматических контактов',
                ];
                $conditionOptions = [];
                foreach ($conditionLabels as $key => $label) {
                    if (data_get($conditions, $key) !== null && data_get($conditions, $key) !== []) {
                        $conditionOptions[] = ['key' => $key, 'label' => $label];
                    }
                }
                $topics = DB::table('kn_fact_groups')->where('release_id', $draft)->orderBy('position')->get(['key', 'data'])
                    ->map(function ($item): array {
                        $data = json_decode($item->data ?? '{}', true) ?: [];

                        return ['key' => $item->key, 'label' => ($data['topic'] ?? null) ?: $item->key, 'bridge_hint' => $data['bridge_hint'] ?? '', 'must_not' => $data['must_not'] ?? []];
                    })->all();

                return ['fact' => $row, 'version' => $version, 'history' => $history, 'publishedText' => $publishedText, 'topics' => $topics, 'conditionOptions' => $conditionOptions, 'intentLinks' => $intentLinks,
                    'group' => ['topic' => $group['topic'] ?? null, 'bridge_hint' => $group['bridge_hint'] ?? null, 'must_not' => $group['must_not'] ?? []], 'example' => $group['fact_examples'][$row->logical_id] ?? null];
            }, rescue: true),
            'status' => session('status'),
        ]);
    }

    public function bulk(Request $request): mixed
    {
        $input = $request->validate(['version' => ['required', 'integer', 'min:1'], 'action' => ['required', 'in:activate,deactivate,delete,move'],
            'group' => ['required_if:action,move', 'nullable', 'string', 'max:64'],
            'ids' => ['required', 'array', 'min:1', 'max:50'], 'ids.*' => ['required', 'ulid', 'distinct']]);
        $this->access($request, function (TenantContext $tenant) use ($input): void {
            DB::transaction(function () use ($tenant, $input): void {
                $draft = $this->draft($tenant);
                $repo = app(KnowledgeRepository::class);
                $version = (int) $input['version'];
                foreach ($input['ids'] as $id) {
                    $fact = DB::table('kn_facts as f')->join('kn_fact_groups as g', 'g.id', '=', 'f.group_id')
                        ->where('g.release_id', $draft)->where('f.id', $id)->first(['f.text', 'f.source']) ?? abort(404);
                    if ($input['action'] === 'move') {
                        $group = DB::table('kn_fact_groups')->where('release_id', $draft)->where('key', $input['group'])->first(['id']) ?? abort(404);
                        $position = DB::table('kn_facts')->where('group_id', $group->id)->where('id', '!=', $id)->count();
                        $version = $repo->moveFact($tenant, $draft, $id, $version, $input['group'], $position);
                    } elseif ($input['action'] === 'delete') {
                        $version = $repo->deleteFact($tenant, $draft, $id, $version);
                    } else {
                        $version = $repo->setFact($tenant, $draft, $id, $version, $fact->text,
                            $input['action'] === 'activate' ? 'active' : 'inactive', $fact->source);
                    }
                }
            });
        });

        return back()->with('status', 'Выбранные факты обновлены.');
    }

    public function group(Request $request): mixed
    {
        $input = $request->validate(['version' => ['required', 'integer', 'min:1'], 'key' => ['required', 'regex:/^[a-z0-9_-]{1,64}$/'],
            'create' => ['sometimes', 'boolean'], 'topic' => ['nullable', 'string', 'max:100'], 'bridge_hint' => ['nullable', 'string', 'max:500'], 'must_not' => ['nullable', 'string', 'max:2000']]);
        $this->access($request, function (TenantContext $tenant) use ($input): void {
            $repo = app(KnowledgeRepository::class);
            $id = $this->draft($tenant);
            $data = $repo->forRelease($tenant, $id, true)->toArray();
            $index = collect($data['facts'])->search(fn ($group) => $group['id'] === $input['key']);
            if (($input['create'] ?? false) && $index !== false) {
                throw ValidationException::withMessages(['key' => 'Группа с таким ключом уже существует.']);
            }
            $group = $index === false ? ['id' => $input['key'], 'facts' => []] : $data['facts'][$index];
            $group['topic'] = $input['topic'] ?? '';
            $group['bridge_hint'] = $input['bridge_hint'] ?? '';
            $group['must_not'] = array_values(array_filter(array_map('trim', preg_split('/\R/u', $input['must_not'] ?? '')), fn ($line) => $line !== ''));
            if ($index === false) {
                $data['facts'][] = $group;
            } else {
                $data['facts'][$index] = $group;
            }
            $repo->saveDraft($tenant, $id, (int) $input['version'], $data);
        });

        return back()->with('status', 'Группа сохранена.');
    }

    public function deleteGroup(Request $request): mixed
    {
        $input = $request->validate(['version' => ['required', 'integer', 'min:1'], 'key' => ['required', 'string', 'max:64']]);
        $this->access($request, function (TenantContext $tenant) use ($input): void {
            $repo = app(KnowledgeRepository::class);
            $id = $this->draft($tenant);
            $data = $repo->forRelease($tenant, $id, true)->toArray();
            $index = collect($data['facts'])->search(fn ($group) => $group['id'] === $input['key']);
            if ($index === false) {
                abort(404);
            }
            if ($data['facts'][$index]['facts'] !== []) {
                throw ValidationException::withMessages(['key' => 'Сначала удалите факты из группы.']);
            }
            array_splice($data['facts'], $index, 1);
            $repo->saveDraft($tenant, $id, (int) $input['version'], $data);
        });

        return back()->with('status', 'Группа удалена.');
    }

    public function add(Request $request): mixed
    {
        $input = $request->validate(['version' => ['required', 'integer', 'min:1'], 'group' => ['required', 'string', 'max:64'], 'text' => ['required', 'string', 'max:500']]);
        $created = $this->access($request, fn (TenantContext $tenant) => app(KnowledgeRepository::class)->addFact($tenant, $this->draft($tenant), (int) $input['version'], $input['group'], trim($input['text'])));

        return redirect('/seller/knowledge/facts/'.$created['id'])->with('status', 'Факт добавлен. Заполните остальные поля в карточке.');
    }

    public function update(Request $request, string $fact): mixed
    {
        $input = $request->validate(['version' => ['required', 'integer', 'min:1'], 'text' => ['required', 'string', 'max:500'], 'status' => ['required', 'in:active,inactive'], 'source' => ['required', 'in:manual,import,url'], 'group' => ['sometimes', 'required', 'string', 'max:64'], 'bridge_hint' => ['sometimes', 'nullable', 'string', 'max:500'], 'must_not' => ['sometimes', 'nullable', 'string', 'max:2000'], 'intent_ids' => ['sometimes', 'array'], 'intent_ids.*' => ['string', 'distinct', 'max:100']]);
        $this->access($request, function (TenantContext $tenant) use ($fact, $input): void {
            DB::transaction(function () use ($tenant, $fact, $input): void {
                $draft = $this->draft($tenant);
                $repository = app(KnowledgeRepository::class);
                $version = (int) $input['version'];
                if (isset($input['group'])) {
                    $current = DB::table('kn_facts as f')->join('kn_fact_groups as g', 'g.id', '=', 'f.group_id')
                        ->where('g.release_id', $draft)->where('f.id', $fact)->value('g.key') ?? abort(404);
                    $target = DB::table('kn_fact_groups')->where('release_id', $draft)->where('key', $input['group'])->first(['id']) ?? abort(404);
                    if ($current !== $input['group']) {
                        $position = DB::table('kn_facts')->where('group_id', $target->id)->count();
                        $version = $repository->moveFact($tenant, $draft, $fact, $version, $input['group'], $position);
                    }
                }
                $version = $repository->setFact($tenant, $draft, $fact, $version, trim($input['text']), $input['status'], $input['source']);
                if (array_key_exists('bridge_hint', $input) || array_key_exists('must_not', $input) || array_key_exists('intent_ids', $input)) {
                    $key = DB::table('kn_facts as f')->join('kn_fact_groups as g', 'g.id', '=', 'f.group_id')
                        ->where('g.release_id', $draft)->where('f.id', $fact)->value('g.key') ?? abort(404);
                    $data = $repository->forRelease($tenant, $draft, true)->toArray();
                    $index = collect($data['facts'])->search(fn ($group) => $group['id'] === $key);
                    abort_if($index === false, 404);
                    if (array_key_exists('bridge_hint', $input)) {
                        $data['facts'][$index]['bridge_hint'] = trim($input['bridge_hint'] ?? '');
                    }
                    if (array_key_exists('must_not', $input)) {
                        $data['facts'][$index]['must_not'] = array_values(array_filter(array_map('trim', preg_split('/\R/u', $input['must_not'] ?? '')), fn ($line) => $line !== ''));
                    }
                    if (array_key_exists('intent_ids', $input)) {
                        $known = array_column($data['intents'], 'id');
                        if (array_diff($input['intent_ids'], $known)) {
                            throw ValidationException::withMessages(['intent_ids' => 'Выберите существующие темы вопросов.']);
                        }
                        $rows = DB::table('kn_facts as f')->join('kn_fact_groups as g', 'g.id', '=', 'f.group_id')
                            ->where('g.release_id', $draft)->where('g.key', $key)->orderBy('f.position')->pluck('f.logical_id')->all();
                        $logicalId = DB::table('kn_facts')->where('id', $fact)->value('logical_id') ?? abort(404);
                        foreach ($data['intents'] as &$intent) {
                            $groups = $intent['facts'] ?? [];
                            $hasGroup = in_array($key, $groups, true);
                            $selected = in_array($intent['id'], $input['intent_ids'], true);
                            $overrides = $intent['fact_overrides'] ?? [];
                            $wasSelected = in_array($key, array_slice($groups, 0, 3), true)
                                && (! array_key_exists($key, $overrides) || in_array($logicalId, $overrides[$key], true));
                            if ($selected === $wasSelected) {
                                continue;
                            }
                            if ($selected && ! in_array($key, array_slice($groups, 0, 3), true) && count($groups) >= 3) {
                                throw ValidationException::withMessages(['intent_ids' => 'В теме вопросов уже выбраны три группы фактов.']);
                            }
                            $ids = $overrides[$key] ?? ($hasGroup ? $rows : []);
                            $ids = array_values(array_filter($ids, fn ($id) => $id !== $logicalId));
                            if ($selected) {
                                $ids[] = $logicalId;
                            }
                            if (! $hasGroup) {
                                $intent['facts'][] = $key;
                            }
                            $intent['fact_overrides'][$key] = $ids;
                        }
                        unset($intent);
                    }
                    $repository->saveDraft($tenant, $draft, $version, $data);
                }
            });
        });

        return back()->with('status', 'Факт сохранён.');
    }

    public function example(Request $request, string $fact): mixed
    {
        $input = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $this->access($request, function (TenantContext $tenant) use ($fact, $input): void {
            abort_unless(config('runtime.ai_driver', 'fake') === 'fake', 403);
            $draft = $this->draft($tenant);
            $row = DB::table('kn_facts as f')->join('kn_fact_groups as g', 'g.id', '=', 'f.group_id')
                ->where('g.release_id', $draft)->where('f.id', $fact)->first(['f.text', 'f.logical_id', 'g.key']) ?? abort(404);
            $repo = app(KnowledgeRepository::class);
            $data = $repo->forRelease($tenant, $draft, true)->toArray();
            $index = collect($data['facts'])->search(fn ($group) => $group['id'] === $row->key);
            abort_if($index === false, 404);
            $snapshot = $repo->forRelease($tenant, $draft, true);
            $data['facts'][$index]['fact_examples'][$row->logical_id] = app(FactExampleFakeDriver::class)->generate($snapshot->resolvePlaceholders($row->text));
            $repo->saveDraft($tenant, $draft, (int) $input['version'], $data);
        });

        return back()->with('status', 'Тестовый пример сохранён в черновике.');
    }

    public function delete(Request $request, string $fact): mixed
    {
        $input = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $this->access($request, fn (TenantContext $tenant) => app(KnowledgeRepository::class)->deleteFact($tenant, $this->draft($tenant), $fact, (int) $input['version']));

        return back()->with('status', 'Факт удалён.');
    }
}
