<?php

namespace App\Seller\Settings\Persona;

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

final class PersonaController
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
            $persona = $draft ? app(KnowledgeRepository::class)->forRelease($tenant, $draft->id, true)->persona() : null;

            return ['draft' => $draft, 'persona' => $persona];
        });

        return Inertia::render('Seller/Settings/Persona/Index', [...$data, 'status' => session('status')]);
    }

    public function save(Request $request): mixed
    {
        $input = $request->validate(['version' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:40'], 'intro' => ['nullable', 'string', 'max:400'],
            'tone' => ['present', 'array', 'max:8'], 'tone.*' => ['required', 'string', 'max:300'], 'form_bans' => ['present', 'array', 'max:30'], 'form_bans.*' => ['required', 'string', 'max:300'],
            'job_title' => ['nullable', 'string', 'max:100'], 'bot_disclosure' => ['nullable', 'in:always,asked'], 'address' => ['nullable', 'in:vy,ty'], 'anchors' => ['nullable', 'array', 'max:30'], 'anchors.*' => ['string', 'max:300'], 'service_phrases' => ['nullable', 'array'], 'service_phrases.*' => ['nullable', 'string', 'max:500'], 'quick_replies' => ['nullable', 'array', 'max:30'], 'quick_replies.*' => ['string', 'max:500'],
            'max_words' => ['required', 'integer', 'min:20', 'max:300'], 'target_min' => ['required', 'integer', 'min:1', 'max:300'], 'target_max' => ['required', 'integer', 'min:1', 'max:300'], 'max_questions' => ['required', 'integer', 'min:1', 'max:2']]);
        if ($input['target_min'] > $input['target_max'] || $input['target_max'] > $input['max_words']) {
            throw ValidationException::withMessages(['target_max' => 'Диапазон должен быть по возрастанию и не превышать максимум слов.']);
        }
        $this->access($request, function (TenantContext $tenant) use ($input): void {
            $draft = $this->draft($tenant) ?? abort(404, 'Создайте черновик базы знаний.');
            $repo = app(KnowledgeRepository::class);
            $data = $repo->forRelease($tenant, $draft->id, true)->toArray();
            $persona = $data['persona'];
            $persona['name'] = $input['name'];
            $persona['intro'] = $input['intro'] ?? '';
            $persona['tone'] = $input['tone'];
            $persona['form_bans'] = $input['form_bans'];
            foreach (['job_title', 'bot_disclosure', 'address', 'anchors', 'service_phrases', 'quick_replies'] as $key) {
                if (array_key_exists($key, $input)) {
                    $persona[$key] = $input[$key];
                }
            }
            $persona['length'] = array_replace($persona['length'] ?? [], ['target_words' => [(int) $input['target_min'], (int) $input['target_max']], 'max_words' => (int) $input['max_words'], 'max_questions' => (int) $input['max_questions']]);
            $data['persona'] = $persona;
            $repo->saveDraft($tenant, $draft->id, (int) $input['version'], $data);
        });

        return back()->with('status', 'Персонаж сохранён в черновике.');
    }
}
