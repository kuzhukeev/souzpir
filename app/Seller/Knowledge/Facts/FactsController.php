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
        $data = $this->access($request, function (TenantContext $tenant) use ($fact): array {
            $draft = $this->draft($tenant);
            $row = DB::table('kn_facts as f')->join('kn_fact_groups as g', 'g.id', '=', 'f.group_id')
                ->where('g.release_id', $draft)->where('f.id', $fact)
                ->first(['f.id', 'f.logical_id', 'f.text', 'f.status', 'f.source', 'g.key as group_key']) ?? abort(404);
            $version = DB::table('knowledge_releases')->where('id', $draft)->value('version');
            $history = DB::table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')
                ->where('a.organization_id', $tenant->organizationId)->where('a.brand_id', $tenant->brandId)
                ->where('a.subject_type', 'knowledge_fact')->where('a.subject_id', $row->logical_id)
                ->orderByDesc('a.created_at')->limit(50)->get(['a.action', 'a.created_at', 'u.name as author']);

            return ['fact' => $row, 'version' => $version, 'history' => $history];
        });

        return Inertia::render('Seller/Knowledge/Facts/Show', [...$data, 'status' => session('status')]);
    }

    public function bulk(Request $request): mixed
    {
        $input = $request->validate(['version' => ['required', 'integer', 'min:1'], 'action' => ['required', 'in:activate,deactivate,delete'],
            'ids' => ['required', 'array', 'min:1', 'max:50'], 'ids.*' => ['required', 'ulid', 'distinct']]);
        $this->access($request, function (TenantContext $tenant) use ($input): void {
            DB::transaction(function () use ($tenant, $input): void {
                $draft = $this->draft($tenant);
                $repo = app(KnowledgeRepository::class);
                $version = (int) $input['version'];
                foreach ($input['ids'] as $id) {
                    $fact = DB::table('kn_facts as f')->join('kn_fact_groups as g', 'g.id', '=', 'f.group_id')
                        ->where('g.release_id', $draft)->where('f.id', $id)->first(['f.text', 'f.source']) ?? abort(404);
                    $version = $input['action'] === 'delete'
                        ? $repo->deleteFact($tenant, $draft, $id, $version)
                        : $repo->setFact($tenant, $draft, $id, $version, $fact->text,
                            $input['action'] === 'activate' ? 'active' : 'inactive', $fact->source);
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
        $this->access($request, fn (TenantContext $tenant) => app(KnowledgeRepository::class)->addFact($tenant, $this->draft($tenant), (int) $input['version'], $input['group'], trim($input['text'])));

        return back()->with('status', 'Факт добавлен.');
    }

    public function update(Request $request, string $fact): mixed
    {
        $input = $request->validate(['version' => ['required', 'integer', 'min:1'], 'text' => ['required', 'string', 'max:500'], 'status' => ['required', 'in:active,inactive'], 'source' => ['required', 'in:manual,import,url']]);
        $this->access($request, fn (TenantContext $tenant) => app(KnowledgeRepository::class)->setFact($tenant, $this->draft($tenant), $fact, (int) $input['version'], trim($input['text']), $input['status'], $input['source']));

        return back()->with('status', 'Факт сохранён.');
    }

    public function delete(Request $request, string $fact): mixed
    {
        $input = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $this->access($request, fn (TenantContext $tenant) => app(KnowledgeRepository::class)->deleteFact($tenant, $this->draft($tenant), $fact, (int) $input['version']));

        return back()->with('status', 'Факт удалён.');
    }
}
