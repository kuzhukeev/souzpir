<?php

namespace App\Seller\Knowledge\Prohibitions;

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

            return ['draft' => $draft, 'topics' => $data['closed_topics'] ?? [], 'rules' => $data['guard_rules']['forbidden_promises'] ?? [], 'maxWords' => $data['persona']['length']['max_words'] ?? 90];
        });

        return Inertia::render('Seller/Knowledge/Prohibitions/Index', [...$data, 'status' => session('status')]);
    }

    public function save(Request $request): mixed
    {
        $input = $request->validate(['version' => ['required', 'integer', 'min:1'], 'topics' => ['present', 'array', 'max:100'], 'topics.*.id' => ['required', 'string', 'max:100'],
            'topics.*.category' => ['required', 'string', 'max:100'], 'topics.*.idea' => ['required', 'string', 'max:300'], 'topics.*.next' => ['required', 'in:return,handoff'],
            'rules' => ['present', 'array', 'max:100'], 'rules.*.id' => ['required', 'string', 'max:100'], 'rules.*.severity' => ['required', 'in:block,warn'],
            'maxWords' => ['required', 'integer', 'min:20', 'max:300']]);
        $this->access($request, function (TenantContext $tenant) use ($input): void {
            $draft = $this->draft($tenant) ?? abort(404);
            $repo = app(KnowledgeRepository::class);
            $data = $repo->forRelease($tenant, $draft->id, true)->toArray();
            $topics = collect($data['closed_topics'])->keyBy('id');
            $rules = collect($data['guard_rules']['forbidden_promises'] ?? [])->keyBy('id');
            if (count($input['topics']) !== $topics->count() || count($input['rules']) !== $rules->count()
                || count(array_unique(array_column($input['topics'], 'id'))) !== count($input['topics']) || count(array_unique(array_column($input['rules'], 'id'))) !== count($input['rules'])) {
                throw ValidationException::withMessages(['topics' => 'Список правил изменился; обновите страницу.']);
            }
            foreach ($input['topics'] as $item) {
                if (! $topics->has($item['id'])) {
                    throw ValidationException::withMessages(['topics' => 'Неизвестная тема.']);
                }
                $original = $topics[$item['id']];
                $original['category'] = $item['category'];
                $original['idea'] = $item['idea'];
                $original['next'] = $item['next'];
                $topics[$item['id']] = $original;
            }
            foreach ($input['rules'] as $item) {
                if (! $rules->has($item['id'])) {
                    throw ValidationException::withMessages(['rules' => 'Неизвестное правило.']);
                }
                $original = $rules[$item['id']];
                $original['severity'] = $item['severity'];
                $rules[$item['id']] = $original;
            }
            $data['closed_topics'] = $topics->values()->all();
            $data['guard_rules']['forbidden_promises'] = $rules->values()->all();
            $data['persona']['length'] ??= [];
            $data['persona']['length']['max_words'] = (int) $input['maxWords'];
            $repo->saveDraft($tenant, $draft->id, (int) $input['version'], $data);
        });

        return back()->with('status', 'Правила сохранены в черновике.');
    }
}
